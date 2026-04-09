<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT id, username, role, COALESCE(status, "active") as status, created_at FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }
            echo json_encode(['item' => $user]);
        } else {
            $stmt = $pdo->query('SELECT id, username, role, COALESCE(status, "active") as status, created_at FROM users ORDER BY id DESC');
            echo json_encode(['items' => $stmt->fetchAll()]);
        }
        exit;
    }

    if ($method === 'POST') {
        $data = json_input();
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $role = (string)($data['role'] ?? 'user');
        if ($username === '' || $password === '' || !in_array($role, ['admin','user'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid input']);
            exit;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $hash, $role, 'active']);
            echo json_encode(['id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            http_response_code(409);
            echo json_encode(['error' => 'username exists']);
        }
        exit;
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        $data = json_input();
        $fields = [];
        $params = [];
        
        // Check if username is being updated
        if (isset($data['username']) && trim($data['username']) !== '') {
            $newUsername = trim((string)$data['username']);
            
            // Check if username already exists (excluding current user)
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $checkStmt->execute([$newUsername, $id]);
            if ($checkStmt->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Username already exists']);
                exit;
            }
            
            $fields[] = 'username = ?';
            $params[] = $newUsername;
        }
        
        if (isset($data['role']) && in_array($data['role'], ['admin','user'], true)) { $fields[] = 'role = ?'; $params[] = (string)$data['role']; }
        if (isset($data['status']) && in_array($data['status'], ['active','inactive'], true)) { $fields[] = 'status = ?'; $params[] = (string)$data['status']; }
        if (isset($data['password']) && $data['password'] !== '') { $fields[] = 'password_hash = ?'; $params[] = password_hash((string)$data['password'], PASSWORD_DEFAULT); }
        if (empty($fields)) { echo json_encode(['updated' => 0]); exit; }
        $params[] = $id;
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
        echo json_encode(['updated' => $stmt->rowCount()]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        if ($id === (int)$_SESSION['user_id']) { http_response_code(409); echo json_encode(['error' => 'cannot delete yourself']); exit; }
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['deleted' => $stmt->rowCount()]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log("Users API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error', 'debug' => $e->getMessage()]);
}