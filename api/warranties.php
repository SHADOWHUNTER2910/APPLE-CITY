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
        $imei   = $_GET['imei'] ?? '';
        $status = $_GET['status'] ?? null; // active | expired | all
        $q      = $_GET['q'] ?? '';

        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM warranties WHERE id = ?');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if (!$item) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            echo json_encode(['item' => $item]);
            exit;
        }

        if ($imei !== '') {
            $stmt = $pdo->prepare('SELECT * FROM warranties WHERE imei = ? ORDER BY created_at DESC');
            $stmt->execute([$imei]);
            echo json_encode(['items' => $stmt->fetchAll()]);
            exit;
        }

        $where = ['1=1']; $params = [];
        if ($status === 'active') {
            $where[] = 'end_date >= DATE("now")';
        } elseif ($status === 'expired') {
            $where[] = 'end_date < DATE("now")';
        }
        if ($q !== '') {
            $where[] = '(imei LIKE ? OR customer_name LIKE ? OR product_name LIKE ?)';
            $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
        }
        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT *, CAST((julianday(end_date) - julianday('now')) AS INTEGER) as days_remaining FROM warranties WHERE $whereStr ORDER BY end_date ASC LIMIT 500");
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($method === 'POST') {
        $data         = json_input();
        $receiptId    = (int)($data['receipt_id'] ?? 0);
        $imei         = trim((string)($data['imei'] ?? ''));
        $productName  = trim((string)($data['product_name'] ?? ''));
        $customerName = trim((string)($data['customer_name'] ?? ''));
        $customerPhone = trim((string)($data['customer_phone'] ?? ''));
        $months       = max(1, (int)($data['warranty_months'] ?? 12));
        $startDate    = trim((string)($data['start_date'] ?? date('Y-m-d')));
        $notes        = trim((string)($data['notes'] ?? ''));

        if ($receiptId <= 0 || $imei === '' || $productName === '') {
            http_response_code(400);
            echo json_encode(['error' => 'receipt_id, imei, and product_name are required']);
            exit;
        }

        $endDate = date('Y-m-d', strtotime($startDate . " +$months months"));

        $stmt = $pdo->prepare('INSERT INTO warranties (receipt_id, imei, product_name, customer_name, customer_phone, warranty_months, start_date, end_date, notes)
                               VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$receiptId, $imei, $productName, $customerName ?: null, $customerPhone ?: null, $months, $startDate, $endDate, $notes ?: null]);
        echo json_encode(['id' => (int)$pdo->lastInsertId(), 'end_date' => $endDate]);
        exit;
    }

    if ($method === 'PUT') {
        $id   = (int)($_GET['id'] ?? 0);
        $data = json_input();
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $fields = []; $params = [];
        foreach (['status', 'notes', 'warranty_months'] as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
        }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
        $params[] = $id;
        $pdo->prepare('UPDATE warranties SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        echo json_encode(['updated' => 1]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
