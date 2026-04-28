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
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $status = $_GET['status'] ?? null;
        $q      = $_GET['q'] ?? '';

        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT t.*, u.username as created_by_username FROM trade_ins t LEFT JOIN users u ON t.created_by = u.id WHERE t.id = ?');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if (!$item) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            echo json_encode(['item' => $item]);
            exit;
        }

        $where = ['1=1']; $params = [];
        if ($status) { $where[] = 't.status = ?'; $params[] = $status; }
        if ($q !== '') {
            $where[] = '(t.customer_name LIKE ? OR t.imei LIKE ? OR t.device_model LIKE ?)';
            $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
        }
        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT t.*, u.username as created_by_username FROM trade_ins t LEFT JOIN users u ON t.created_by = u.id WHERE $whereStr ORDER BY t.created_at DESC LIMIT 200");
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($method === 'POST') {
        $data = json_input();
        $customerName  = trim((string)($data['customer_name'] ?? ''));
        $customerPhone = trim((string)($data['customer_phone'] ?? ''));
        $deviceModel   = trim((string)($data['device_model'] ?? ''));
        $imei          = trim((string)($data['imei'] ?? ''));
        $condition     = trim((string)($data['condition'] ?? 'good'));
        $offeredValue  = (float)($data['offered_value'] ?? 0);
        $agreedValue   = (float)($data['agreed_value'] ?? $offeredValue);
        $linkedReceipt = isset($data['linked_receipt_id']) && $data['linked_receipt_id'] ? (int)$data['linked_receipt_id'] : null;
        $notes         = trim((string)($data['notes'] ?? ''));

        if ($customerName === '' || $deviceModel === '') {
            http_response_code(400); echo json_encode(['error' => 'customer_name and device_model are required']); exit;
        }

        $stmt = $pdo->prepare('INSERT INTO trade_ins (customer_name, customer_phone, device_model, imei, condition, offered_value, agreed_value, linked_receipt_id, notes, created_by)
                               VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$customerName, $customerPhone ?: null, $deviceModel, $imei ?: null, $condition,
                        $offeredValue, $agreedValue, $linkedReceipt, $notes ?: null, $_SESSION['user_id']]);
        echo json_encode(['id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($method === 'PUT') {
        $id   = (int)($_GET['id'] ?? 0);
        $data = json_input();
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $fields = []; $params = [];
        $editable = ['customer_name','customer_phone','device_model','imei','condition','offered_value','agreed_value','linked_receipt_id','status','notes','added_to_inventory','inventory_imei_id'];
        foreach ($editable as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = $data[$f] === '' ? null : $data[$f]; }
        }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
        $params[] = $id;
        $pdo->prepare('UPDATE trade_ins SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        echo json_encode(['updated' => 1]);
        exit;
    }

    if ($method === 'DELETE') {
        if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        $pdo->prepare('DELETE FROM trade_ins WHERE id = ?')->execute([$id]);
        echo json_encode(['deleted' => 1]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
