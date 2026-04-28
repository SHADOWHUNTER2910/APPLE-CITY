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
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        if ($productId > 0) {
            $stmt = $pdo->prepare('
                SELECT v.*,
                       COUNT(u.id) as total_units,
                       SUM(CASE WHEN u.status = "in_stock" THEN 1 ELSE 0 END) as in_stock,
                       SUM(CASE WHEN u.status = "sold" THEN 1 ELSE 0 END) as sold
                FROM product_variants v
                LEFT JOIN imei_units u ON u.variant_id = v.id
                WHERE v.product_id = ?
                GROUP BY v.id
                ORDER BY v.storage, v.color
            ');
            $stmt->execute([$productId]);
            echo json_encode(['items' => $stmt->fetchAll()]);
        } else {
            $stmt = $pdo->query('
                SELECT v.*, p.name as product_name,
                       COUNT(u.id) as total_units,
                       SUM(CASE WHEN u.status = "in_stock" THEN 1 ELSE 0 END) as in_stock
                FROM product_variants v
                JOIN products p ON v.product_id = p.id
                LEFT JOIN imei_units u ON u.variant_id = v.id
                GROUP BY v.id
                ORDER BY p.name, v.storage, v.color
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
        }
        exit;
    }

    if ($method === 'POST') {
        if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
        $data      = json_input();
        $productId = (int)($data['product_id'] ?? 0);
        $storage   = trim((string)($data['storage'] ?? ''));
        $color     = trim((string)($data['color'] ?? ''));
        $sellPrice = (float)($data['selling_price'] ?? 0);
        $costPrice = (float)($data['cost_price'] ?? 0);

        if ($productId <= 0) { http_response_code(400); echo json_encode(['error' => 'product_id required']); exit; }

        try {
            $stmt = $pdo->prepare('INSERT INTO product_variants (product_id, storage, color, selling_price, cost_price) VALUES (?,?,?,?,?)');
            $stmt->execute([$productId, $storage, $color, $sellPrice, $costPrice]);
            echo json_encode(['id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                http_response_code(409);
                echo json_encode(['error' => 'This storage/color combination already exists for this product']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        }
        exit;
    }

    if ($method === 'PUT') {
        if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
        $id   = (int)($_GET['id'] ?? 0);
        $data = json_input();
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $fields = []; $params = [];
        foreach (['storage', 'color', 'selling_price', 'cost_price'] as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
        }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
        $params[] = $id;
        $pdo->prepare('UPDATE product_variants SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        echo json_encode(['updated' => 1]);
        exit;
    }

    if ($method === 'DELETE') {
        if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        // Check if any IMEI units use this variant
        $chk = $pdo->prepare('SELECT COUNT(*) FROM imei_units WHERE variant_id = ?');
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot delete variant with existing IMEI units']);
            exit;
        }
        $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$id]);
        echo json_encode(['deleted' => 1]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
