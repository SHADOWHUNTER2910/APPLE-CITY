<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    // Verify user still exists and is active
    $pdo = get_pdo();
    
    try {
        $stmt = $pdo->prepare('SELECT id, username, role, status FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        // Fallback if status column doesn't exist
        error_log("Status column not found in me.php, using fallback query: " . $e->getMessage());
        $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            $user['status'] = 'active'; // Default to active for backward compatibility
        }
    }
    
    // If user doesn't exist or is inactive, destroy session
    if (!$user || ($user['status'] ?? 'active') !== 'active') {
        session_destroy();
        echo json_encode(['authenticated' => false, 'reason' => 'account_deactivated']);
        exit;
    }
    
    echo json_encode([
        'authenticated' => true,
        'user_id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ]);
} else {
    echo json_encode(['authenticated' => false]);
}