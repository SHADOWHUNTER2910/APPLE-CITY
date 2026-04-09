<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!isset($_SESSION['user_id'])) { 
    http_response_code(401); 
    echo json_encode(['error' => 'unauthenticated']); 
    exit; 
}

try {
    if ($method === 'GET') {
        $productId = $_GET['product_id'] ?? null;
        
        if ($productId) {
            // Get movements for a specific product
            $stmt = $pdo->prepare('
                SELECT 
                    sm.*,
                    p.name as product_name,
                    p.sku,
                    u.username as created_by_username
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.created_by = u.id
                WHERE sm.product_id = ?
                ORDER BY sm.created_at DESC
                LIMIT 100
            ');
            $stmt->execute([$productId]);
            echo json_encode(['items' => $stmt->fetchAll()]);
        } else {
            // Get recent movements for all products
            $stmt = $pdo->query('
                SELECT 
                    sm.*,
                    p.name as product_name,
                    p.sku,
                    u.username as created_by_username
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.created_by = u.id
                ORDER BY sm.created_at DESC
                LIMIT 100
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error: ' . $e->getMessage()]);
}
