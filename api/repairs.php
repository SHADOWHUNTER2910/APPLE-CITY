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

function generate_job_number(PDO $pdo): string {
    $prefix = 'JOB-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT job_number FROM repairs WHERE job_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = $last ? ((int)substr($last, -4) + 1) : 1;
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

try {
    if ($method === 'GET') {
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $status = $_GET['status'] ?? null;
        $q      = $_GET['q'] ?? '';

        if ($id > 0) {
            $stmt = $pdo->prepare('
                SELECT r.*, u.username as created_by_username
                FROM repairs r
                LEFT JOIN users u ON r.created_by = u.id
                WHERE r.id = ?
            ');
            $stmt->execute([$id]);
            $repair = $stmt->fetch();
            if (!$repair) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

            // Get parts
            $parts = $pdo->prepare('SELECT * FROM repair_parts WHERE repair_id = ?');
            $parts->execute([$id]);
            $repair['parts'] = $parts->fetchAll();
            echo json_encode(['item' => $repair]);
            exit;
        }

        $where = ['1=1']; $params = [];
        // Non-admins only see their own repairs
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $where[] = 'r.created_by = ?';
            $params[] = (int)$_SESSION['user_id'];
        }
        if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
        if ($q !== '') {
            $where[] = '(r.job_number LIKE ? OR r.customer_name LIKE ? OR r.imei LIKE ? OR r.device_model LIKE ?)';
            $params = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%"]);
        }
        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT r.*, u.username as created_by_username
            FROM repairs r
            LEFT JOIN users u ON r.created_by = u.id
            WHERE $whereStr
            ORDER BY r.created_at DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll(), 'is_admin' => ($_SESSION['role'] ?? '') === 'admin']);
        exit;
    }

    if ($method === 'POST') {
        $data = json_input();

        // Status update shortcut
        if (isset($data['action']) && $data['action'] === 'update_status') {
            $id        = (int)($data['id'] ?? 0);
            $newStatus = trim((string)($data['status'] ?? ''));
            $validStatuses = ['received', 'diagnosing', 'awaiting_parts', 'in_repair', 'ready', 'collected', 'cancelled'];
            if ($id <= 0 || !in_array($newStatus, $validStatuses)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid id or status']); exit;
            }
            $extra = '';
            $params = [$newStatus];
            if ($newStatus === 'ready' || $newStatus === 'collected') {
                $extra = ', completed_at = CURRENT_TIMESTAMP';
            }
            if ($newStatus === 'collected') {
                $extra .= ', collected_at = CURRENT_TIMESTAMP, payment_status = "paid"';
            }
            $pdo->prepare("UPDATE repairs SET status = ? $extra WHERE id = ?")->execute(array_merge($params, [$id]));
            echo json_encode(['updated' => 1]);
            exit;
        }

        $customerName = trim((string)($data['customer_name'] ?? ''));
        $customerPhone = trim((string)($data['customer_phone'] ?? ''));
        $deviceModel  = trim((string)($data['device_model'] ?? ''));
        $imei         = trim((string)($data['imei'] ?? ''));
        $issue        = trim((string)($data['issue_description'] ?? ''));
        $laborCost    = (float)($data['labor_cost'] ?? 0);
        $partsCost    = (float)($data['parts_cost'] ?? 0);
        $totalCharge  = (float)($data['total_charge'] ?? ($laborCost + $partsCost));
        $technician   = trim((string)($data['technician'] ?? ''));
        $notes        = trim((string)($data['notes'] ?? ''));
        $parts        = $data['parts'] ?? [];

        if ($customerName === '' || $deviceModel === '' || $issue === '') {
            http_response_code(400);
            echo json_encode(['error' => 'customer_name, device_model, and issue_description are required']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $jobNumber = generate_job_number($pdo);
            $stmt = $pdo->prepare('
                INSERT INTO repairs (job_number, customer_name, customer_phone, device_model, imei, issue_description,
                                     labor_cost, parts_cost, total_charge, technician, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ');
            $stmt->execute([$jobNumber, $customerName, $customerPhone, $deviceModel, $imei ?: null,
                            $issue, $laborCost, $partsCost, $totalCharge, $technician ?: null, $notes ?: null, $_SESSION['user_id']]);
            $repairId = (int)$pdo->lastInsertId();

            // Insert parts
            if (is_array($parts)) {
                $partStmt = $pdo->prepare('INSERT INTO repair_parts (repair_id, part_name, quantity, unit_cost, total_cost) VALUES (?,?,?,?,?)');
                foreach ($parts as $p) {
                    $pName = trim((string)($p['part_name'] ?? ''));
                    $pQty  = max(1, (int)($p['quantity'] ?? 1));
                    $pCost = (float)($p['unit_cost'] ?? 0);
                    if ($pName !== '') {
                        $partStmt->execute([$repairId, $pName, $pQty, $pCost, $pQty * $pCost]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['id' => $repairId, 'job_number' => $jobNumber]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'PUT') {
        $id   = (int)($_GET['id'] ?? 0);
        $data = json_input();
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $fields = []; $params = [];
        $editable = ['customer_name','customer_phone','device_model','imei','issue_description',
                     'diagnosis','status','labor_cost','parts_cost','total_charge',
                     'payment_status','payment_method','technician','notes'];
        foreach ($editable as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f] === '' ? null : $data[$f];
            }
        }
        if (isset($data['status']) && in_array($data['status'], ['ready','collected'])) {
            $fields[] = 'completed_at = CURRENT_TIMESTAMP';
        }
        if (isset($data['status']) && $data['status'] === 'collected') {
            $fields[] = 'collected_at = CURRENT_TIMESTAMP';
        }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
        $params[] = $id;
        $pdo->prepare('UPDATE repairs SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        // Update parts if provided
        if (isset($data['parts']) && is_array($data['parts'])) {
            $pdo->prepare('DELETE FROM repair_parts WHERE repair_id = ?')->execute([$id]);
            $partStmt = $pdo->prepare('INSERT INTO repair_parts (repair_id, part_name, quantity, unit_cost, total_cost) VALUES (?,?,?,?,?)');
            foreach ($data['parts'] as $p) {
                $pName = trim((string)($p['part_name'] ?? ''));
                $pQty  = max(1, (int)($p['quantity'] ?? 1));
                $pCost = (float)($p['unit_cost'] ?? 0);
                if ($pName !== '') $partStmt->execute([$id, $pName, $pQty, $pCost, $pQty * $pCost]);
            }
        }

        echo json_encode(['updated' => 1]);
        exit;
    }

    if ($method === 'DELETE') {
        if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

        // Bulk delete: ?ids=1,2,3
        if (isset($_GET['ids'])) {
            $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
            if (empty($ids)) { http_response_code(400); echo json_encode(['error' => 'No valid ids']); exit; }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM repair_parts WHERE repair_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM repairs WHERE id IN ($placeholders)")->execute($ids);
            echo json_encode(['deleted' => count($ids)]);
            exit;
        }

        // Single delete: ?id=1
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        $pdo->prepare('DELETE FROM repair_parts WHERE repair_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM repairs WHERE id = ?')->execute([$id]);
        echo json_encode(['deleted' => 1]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
