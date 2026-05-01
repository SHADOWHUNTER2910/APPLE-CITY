<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'unauthenticated']); exit; }

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

try {
    if ($method === 'GET') {
        $productId = $_GET['product_id'] ?? null;
        $status    = $_GET['status'] ?? null;   // in_stock | sold | all
        $q         = $_GET['q'] ?? '';           // search by IMEI
        $id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        // Single unit lookup
        if ($id > 0) {
            $stmt = $pdo->prepare('
                SELECT u.*, p.name as product_name, p.sku,
                       v.storage, v.color as variant_color,
                       s.name as supplier_name
                FROM imei_units u
                JOIN products p ON u.product_id = p.id
                LEFT JOIN product_variants v ON u.variant_id = v.id
                LEFT JOIN suppliers s ON u.supplier_id = s.id
                WHERE u.id = ?
            ');
            $stmt->execute([$id]);
            $unit = $stmt->fetch();
            if (!$unit) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            echo json_encode(['item' => $unit]);
            exit;
        }

        // IMEI lookup (for warranty / receipt check)
        if ($q !== '') {
            $stmt = $pdo->prepare('
                SELECT u.*, p.name as product_name, p.sku,
                       v.storage, v.color as variant_color,
                       s.name as supplier_name
                FROM imei_units u
                JOIN products p ON u.product_id = p.id
                LEFT JOIN product_variants v ON u.variant_id = v.id
                LEFT JOIN suppliers s ON u.supplier_id = s.id
                WHERE u.imei LIKE ?
                LIMIT 20
            ');
            $stmt->execute(['%' . $q . '%']);
            echo json_encode(['items' => $stmt->fetchAll()]);
            exit;
        }

        $where = ['1=1'];
        $params = [];

        if ($productId) { $where[] = 'u.product_id = ?'; $params[] = (int)$productId; }
        if ($status && $status !== 'all') { $where[] = 'u.status = ?'; $params[] = $status; }

        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT u.*, p.name as product_name, p.sku,
                   v.storage, v.color as variant_color,
                   s.name as supplier_name
            FROM imei_units u
            JOIN products p ON u.product_id = p.id
            LEFT JOIN product_variants v ON u.variant_id = v.id
            LEFT JOIN suppliers s ON u.supplier_id = s.id
            WHERE $whereStr
            ORDER BY u.created_at DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($method === 'POST') {
        // Only admins can add IMEI units
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403); echo json_encode(['error' => 'Admin only']); exit;
        }

        $data       = json_input();
        $productId  = (int)($data['product_id'] ?? 0);
        $imei       = trim((string)($data['imei'] ?? ''));
        $color      = trim((string)($data['color'] ?? ''));
        $storage    = trim((string)($data['storage'] ?? ''));
        $costPrice  = (float)($data['cost_price'] ?? 0);
        $sellPrice  = (float)($data['selling_price'] ?? 0);
        $supplierId = isset($data['supplier_id']) && $data['supplier_id'] ? (int)$data['supplier_id'] : null;
        $purchDate  = trim((string)($data['purchase_date'] ?? ''));
        $notes      = trim((string)($data['notes'] ?? ''));
        $variantId  = isset($data['variant_id']) && $data['variant_id'] ? (int)$data['variant_id'] : null;

        if ($productId <= 0 || $imei === '') {
            http_response_code(400); echo json_encode(['error' => 'product_id and imei are required']); exit;
        }

        // Validate IMEI: 15 digits
        if (!preg_match('/^\d{15}$/', $imei)) {
            http_response_code(400); echo json_encode(['error' => 'IMEI must be exactly 15 digits']); exit;
        }

        $pdo->beginTransaction();
        try {
            // Check duplicate IMEI
            $chk = $pdo->prepare('SELECT id FROM imei_units WHERE imei = ?');
            $chk->execute([$imei]);
            if ($chk->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => 'IMEI already exists in the system']);
                exit;
            }

            // Resolve variant
            if (!$variantId && ($storage !== '' || $color !== '')) {
                $vStmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? AND storage = ? AND color = ?');
                $vStmt->execute([$productId, $storage, $color]);
                $v = $vStmt->fetch();
                if ($v) {
                    $variantId = (int)$v['id'];
                } else {
                    // Auto-create variant
                    $pdo->prepare('INSERT INTO product_variants (product_id, storage, color, selling_price, cost_price) VALUES (?,?,?,?,?)')
                        ->execute([$productId, $storage, $color, $sellPrice, $costPrice]);
                    $variantId = (int)$pdo->lastInsertId();
                }
            }

            $stmt = $pdo->prepare('
                INSERT INTO imei_units (product_id, variant_id, imei, color, storage, cost_price, selling_price, supplier_id, purchase_date, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "in_stock")
            ');
            $stmt->execute([$productId, $variantId, $imei, $color ?: null, $storage ?: null, $costPrice, $sellPrice, $supplierId, $purchDate ?: null, $notes ?: null]);
            $unitId = (int)$pdo->lastInsertId();

            // Update aggregate stock
            $pdo->prepare('INSERT INTO stock (product_id, quantity, initial_quantity) VALUES (?, 1, 1)
                           ON CONFLICT(product_id) DO UPDATE SET quantity = quantity + 1')->execute([$productId]);

            // Record movement
            $getQty = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ?');
            $getQty->execute([$productId]);
            $newQty = (int)($getQty->fetchColumn() ?? 1);
            $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by)
                           VALUES (?, "addition", 1, ?, ?, "imei", ?, ?, ?)')
                ->execute([$productId, $newQty - 1, $newQty, $unitId, "IMEI added: $imei", $_SESSION['user_id']]);

            $pdo->commit();
            echo json_encode(['id' => $unitId, 'imei' => $imei]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add IMEI unit: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'PUT') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403); echo json_encode(['error' => 'Admin only']); exit;
        }
        $id   = (int)($_GET['id'] ?? 0);
        $data = json_input();
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $fields = [];
        $params = [];
        $allowed = ['color', 'storage', 'cost_price', 'selling_price', 'supplier_id', 'purchase_date', 'notes', 'status'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f] === '' ? null : $data[$f];
            }
        }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
        $params[] = $id;
        $pdo->prepare('UPDATE imei_units SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        echo json_encode(['updated' => 1]);
        exit;
    }

    if ($method === 'DELETE') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403); echo json_encode(['error' => 'Admin only']); exit;
        }

        // Bulk delete: ?ids=1,2,3
        if (isset($_GET['ids'])) {
            $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
            if (empty($ids)) { http_response_code(400); echo json_encode(['error' => 'No valid ids']); exit; }

            $pdo->beginTransaction();
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                // Fetch all units to adjust stock
                $units = $pdo->prepare("SELECT product_id, status, variant_id FROM imei_units WHERE id IN ($placeholders) AND status != 'sold'");
                $units->execute($ids);
                $toDelete = $units->fetchAll();

                if (empty($toDelete)) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['error' => 'All selected units are sold and cannot be deleted']);
                    exit;
                }

                $deleteIds = array_column($toDelete, null);
                $delPlaceholders = implode(',', array_fill(0, count($toDelete), '?'));
                // Re-fetch IDs of non-sold units
                $nonSoldIds = [];
                foreach ($toDelete as $u) { $nonSoldIds[] = $u['product_id']; } // reuse product_ids for stock update

                // Delete the non-sold units
                $pdo->prepare("DELETE FROM imei_units WHERE id IN ($placeholders) AND status != 'sold'")->execute($ids);

                // Adjust stock counts
                $productCounts = [];
                foreach ($toDelete as $u) {
                    $pid = (int)$u['product_id'];
                    $productCounts[$pid] = ($productCounts[$pid] ?? 0) + 1;
                }
                foreach ($productCounts as $pid => $count) {
                    $pdo->prepare('UPDATE stock SET quantity = MAX(0, quantity - ?) WHERE product_id = ?')->execute([$count, $pid]);
                }

                // Clean up orphaned variants
                foreach ($toDelete as $u) {
                    if ($u['variant_id']) {
                        $chk = $pdo->prepare('SELECT COUNT(*) FROM imei_units WHERE variant_id = ?');
                        $chk->execute([$u['variant_id']]);
                        if ((int)$chk->fetchColumn() === 0) {
                            $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$u['variant_id']]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['deleted' => count($toDelete)]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }

        // Single delete
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT product_id, status, variant_id FROM imei_units WHERE id = ?');
            $stmt->execute([$id]);
            $unit = $stmt->fetch();
            if (!$unit) { $pdo->rollBack(); http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            if ($unit['status'] === 'sold') {
                $pdo->rollBack(); http_response_code(409);
                echo json_encode(['error' => 'Cannot delete a sold unit']); exit;
            }
            $pdo->prepare('DELETE FROM imei_units WHERE id = ?')->execute([$id]);
            $pdo->prepare('UPDATE stock SET quantity = MAX(0, quantity - 1) WHERE product_id = ?')->execute([$unit['product_id']]);

            if ($unit['variant_id']) {
                $chkVariant = $pdo->prepare('SELECT COUNT(*) FROM imei_units WHERE variant_id = ?');
                $chkVariant->execute([$unit['variant_id']]);
                if ((int)$chkVariant->fetchColumn() === 0) {
                    $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$unit['variant_id']]);
                }
            }
            $pdo->commit();
            echo json_encode(['deleted' => 1]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
