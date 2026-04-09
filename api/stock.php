<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

try {
    if ($method === 'GET') {
        // Pagination parameters
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $pageSize = isset($_GET['page_size']) ? min(500, max(10, (int)$_GET['page_size'])) : 100;
        $offset = ($page - 1) * $pageSize;
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        
        // If only totals needed (for dashboard) - fast single query
        if (isset($_GET['totals_only']) && $_GET['totals_only'] === '1') {
            $stmt = $pdo->query('
                SELECT 
                    COALESCE(SUM(s.quantity), 0) as total_stock,
                    COUNT(CASE WHEN s.quantity = 0 THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN s.quantity > 0 AND s.quantity < 10 THEN 1 END) as low_stock
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE p.id != 0 AND p.sku != "DELETED"
            ');
            echo json_encode($stmt->fetch());
            exit;
        }
        
        // Build query with optional search
        $whereClause = 'WHERE p.id != 0 AND p.sku != "DELETED"';
        $params = [];
        
        if ($search !== '') {
            $whereClause .= ' AND (p.name LIKE :search OR p.sku LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) FROM products p $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalItems = (int)$countStmt->fetchColumn();
        
        // Get stock quantities with analytics (initial, received, sold)
        $query = "
            SELECT 
                p.id, 
                p.sku, 
                p.name, 
                p.unit_price,
                p.has_expiry,
                COALESCE(s.quantity, 0) as quantity,
                COALESCE(s.initial_quantity, 0) as initial_quantity,
                COALESCE(
                    (SELECT SUM(quantity) FROM stock_movements 
                     WHERE product_id = p.id AND movement_type = 'addition'), 0
                ) as total_received,
                COALESCE(
                    (SELECT SUM(quantity) FROM stock_movements 
                     WHERE product_id = p.id AND movement_type = 'deduction'), 0
                ) as total_sold
            FROM products p 
            LEFT JOIN stock s ON s.product_id = p.id 
            $whereClause
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        echo json_encode([
            'items' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $pageSize)
            ]
        ]);
        exit;
    }

    if ($method === 'PUT') {
        // Update stock for a product
        $id = (int)($_GET['product_id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'product_id is required']); exit; }
        $data = json_input();
        $quantity = isset($data['quantity']) ? (int)$data['quantity'] : null;
        $quantityToAdd = isset($data['quantity_to_add']) ? (int)$data['quantity_to_add'] : null;
        $notes = trim((string)($data['notes'] ?? ''));
        
        try {
            $pdo->beginTransaction();
            
            // Get current quantity and initial quantity
            $stmt = $pdo->prepare('SELECT quantity, initial_quantity FROM stock WHERE product_id = ?');
            $stmt->execute([$id]);
            $stockData = $stmt->fetch();
            $currentQty = (int)($stockData['quantity'] ?? 0);
            $initialQty = (int)($stockData['initial_quantity'] ?? 0);
            
            $newInitialQty = $initialQty; // Default: keep existing initial quantity
            
            // Determine the new quantity
            if ($quantityToAdd !== null) {
                // Adding stock (not replacing)
                $newQuantity = $currentQty + $quantityToAdd;
                $movement = $quantityToAdd;
                $movementType = 'addition';
                
                // IMPORTANT: Set initial quantity ONLY if it's the very first stock addition (both are 0)
                if ($initialQty === 0 && $currentQty === 0) {
                    // First time adding stock - set initial = quantity being added
                    $newInitialQty = $quantityToAdd;
                }
                // Otherwise, initial quantity stays the same (restocking scenario)
                
            } else if ($quantity !== null) {
                // Setting absolute quantity (manual adjustment)
                $newQuantity = $quantity;
                $movement = $newQuantity - $currentQty;
                $movementType = $movement > 0 ? 'addition' : ($movement < 0 ? 'deduction' : 'adjustment');
                
                // Set initial quantity ONLY if it's the very first time (both are 0)
                if ($initialQty === 0 && $currentQty === 0) {
                    // First time setting stock - set initial = quantity being set
                    $newInitialQty = $quantity;
                }
                // Otherwise, initial quantity stays the same
                
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Either quantity or quantity_to_add is required']);
                exit;
            }
            
            // Update stock with both quantity and initial_quantity
            $stmt = $pdo->prepare('
                INSERT OR REPLACE INTO stock (product_id, quantity, initial_quantity) 
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$id, $newQuantity, $newInitialQty]);
            
            // Record movement
            if ($movement != 0) {
                $movementStmt = $pdo->prepare('
                    INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $movementStmt->execute([
                    $id, 
                    $movementType, 
                    abs($movement), 
                    $currentQty, 
                    $newQuantity,
                    'manual',
                    $notes ?: ($quantityToAdd !== null ? 'Stock added' : 'Manual stock adjustment'),
                    $_SESSION['user_id']
                ]);
            }
            
            $pdo->commit();
            echo json_encode([
                'updated' => 1, 
                'movement_recorded' => ($movement != 0),
                'new_quantity' => $newQuantity,
                'new_initial_quantity' => $newInitialQty,
                'quantity_added' => $quantityToAdd ?? 0,
                'initial_set' => ($initialQty === 0 && $currentQty === 0)
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update stock: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error']);
}