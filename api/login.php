<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if ($method === 'POST') {
    $data = json_input();
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'username and password required']);
        exit;
    }
    
    // Try to get user with status column, fallback if column doesn't exist
    try {
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role, status FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
    } catch (PDOException $e) {
        // Fallback if status column doesn't exist
        error_log("Status column not found, using fallback query: " . $e->getMessage());
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if ($u) {
            $u['status'] = 'active'; // Default to active for backward compatibility
        }
    }
    
    if (!$u || !password_verify($password, $u['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid credentials']);
        exit;
    }
    
    // Check if user account is active
    if (($u['status'] ?? 'active') !== 'active') {
        http_response_code(403);
        echo json_encode(['error' => 'account_deactivated', 'message' => 'Your account has been deactivated. Please contact the administrator.']);
        exit;
    }
    
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['role'] = $u['role'];
    echo json_encode(['ok' => true, 'role' => $u['role']]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);