<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'unauthenticated']); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT s.*, COUNT(u.id) as total_units_supplied FROM suppliers s LEFT JOIN imei_units u ON u.supplier_id = s.id WHERE s.id = ? GROUP BY s.id');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if (!$item) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            echo json_encode(['item' => $item]);
            exit;
        }
        $stmt = $pdo->query('SELECT s.*, COUNT(u.id) as total_units_supplied FROM suppliers s LEFT JOIN imei_units u ON u.supplier_id = s.id GROUP BY s.id ORDER BY s.name ASC');
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($method === 'POST') {
        $data  = json_input();
        $name  = trim((string)($data['name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $addr  = trim((string)($data['address'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));

        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name is required']); exit; }

        $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone, email, address, notes) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, $phone ?: null, $email ?: null, $addr ?: null, $notes ?: null]);
        echo json_encode(['id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($method === 'PUT') {
        $id   = (int)($_GET['id'] ?? 0);
        $data = json_input();
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $fields = []; $params = [];
        foreach (['name','phone','email','address','notes'] as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = $data[$f] === '' ? null : $data[$f]; }
        }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
        $params[] = $id;
        $pdo->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        echo json_encode(['updated' => 1]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        // Don't delete if supplier has units
        $chk = $pdo->prepare('SELECT COUNT(*) FROM imei_units WHERE supplier_id = ?');
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot delete supplier with existing IMEI units. Reassign units first.']);
            exit;
        }
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        echo json_encode(['deleted' => 1]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
