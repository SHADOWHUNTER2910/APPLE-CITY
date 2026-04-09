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

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

try {
    if ($method === 'GET') {
        $productId = $_GET['product_id'] ?? null;
        $status = $_GET['status'] ?? null; // 'expiring', 'expired', 'all'
        
        if ($productId) {
            // Get batches for a specific product
            $stmt = $pdo->prepare('
                SELECT 
                    sb.*,
                    sb.id as batch_id,
                    p.name as product_name,
                    p.sku,
                    CAST((julianday(sb.expiry_date) - julianday("now")) AS INTEGER) as days_until_expiry,
                    CASE 
                        WHEN julianday(sb.expiry_date) < julianday("now") THEN "expired"
                        WHEN julianday(sb.expiry_date) <= julianday("now", "+90 days") THEN "expiring_soon"
                        ELSE "good"
                    END as status
                FROM stock_batches sb
                JOIN products p ON sb.product_id = p.id
                WHERE sb.product_id = ?
                ORDER BY sb.expiry_date ASC
            ');
            $stmt->execute([$productId]);
            echo json_encode(['items' => $stmt->fetchAll()]);
        } else if ($status === 'expiring') {
            // Get products expiring soon (within 90 days) but NOT already expired
            $stmt = $pdo->query('
                SELECT 
                    sb.id as batch_id,
                    p.id as product_id,
                    p.sku,
                    p.name as product_name,
                    sb.batch_number,
                    sb.manufacturing_date,
                    sb.expiry_date,
                    sb.quantity,
                    CAST((julianday(sb.expiry_date) - julianday("now")) AS INTEGER) as days_until_expiry,
                    "expiring_soon" as status
                FROM stock_batches sb
                JOIN products p ON sb.product_id = p.id
                WHERE p.has_expiry = 1
                AND julianday(sb.expiry_date) >= julianday("now")
                AND julianday(sb.expiry_date) <= julianday("now", "+90 days")
                ORDER BY sb.expiry_date ASC
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
        } else if ($status === 'expired') {
            // Get expired products
            $stmt = $pdo->query('
                SELECT 
                    sb.id as batch_id,
                    p.id as product_id,
                    p.sku,
                    p.name as product_name,
                    sb.batch_number,
                    sb.manufacturing_date,
                    sb.expiry_date,
                    sb.quantity,
                    CAST((julianday(sb.expiry_date) - julianday("now")) AS INTEGER) as days_until_expiry,
                    "expired" as status
                FROM stock_batches sb
                JOIN products p ON sb.product_id = p.id
                WHERE p.has_expiry = 1
                AND julianday(sb.expiry_date) < julianday("now")
                ORDER BY sb.expiry_date ASC
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
        } else {
            // Get all batches with expiry info
            $stmt = $pdo->query('
                SELECT 
                    sb.id as batch_id,
                    p.id as product_id,
                    p.sku,
                    p.name as product_name,
                    sb.batch_number,
                    sb.manufacturing_date,
                    sb.expiry_date,
                    sb.quantity,
                    CAST((julianday(sb.expiry_date) - julianday("now")) AS INTEGER) as days_until_expiry,
                    CASE 
                        WHEN julianday(sb.expiry_date) < julianday("now") THEN "expired"
                        WHEN julianday(sb.expiry_date) <= julianday("now", "+90 days") THEN "expiring_soon"
                        ELSE "good"
                    END as status
                FROM stock_batches sb
                JOIN products p ON sb.product_id = p.id
                WHERE p.has_expiry = 1
                ORDER BY sb.expiry_date ASC
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
        }
        exit;
    }

    if ($method === 'POST') {
        // Only admins can add batches
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can add batches']);
            exit;
        }
        
        // Add new batch
        $data = json_input();
        $productId = (int)($data['product_id'] ?? 0);
        $batchNumber = trim((string)($data['batch_number'] ?? ''));
        $manufacturingDate = trim((string)($data['manufacturing_date'] ?? ''));
        $expiryDate = trim((string)($data['expiry_date'] ?? ''));
        $quantity = (int)($data['quantity'] ?? 0);
        
        // If no manufacturing date provided, use current date
        if ($manufacturingDate === '') {
            $manufacturingDate = date('Y-m-d');
        }
        
        if ($productId <= 0 || $batchNumber === '' || $expiryDate === '' || $quantity <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'product_id, batch_number, expiry_date, and quantity are required']);
            exit;
        }
        
        // Validate dates
        if ($manufacturingDate && strtotime($manufacturingDate) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid manufacturing date']);
            exit;
        }
        
        if (strtotime($expiryDate) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid expiry date']);
            exit;
        }
        
        // Check if expiry date is in the past
        if (strtotime($expiryDate) < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'Expiry date cannot be in the past']);
            exit;
        }
        
        // Check if manufacturing date is after expiry date
        if ($manufacturingDate && strtotime($manufacturingDate) > strtotime($expiryDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Manufacturing date cannot be after expiry date']);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            // Get current stock quantity and initial quantity
            $getStock = $pdo->prepare('SELECT quantity, initial_quantity FROM stock WHERE product_id = ?');
            $getStock->execute([$productId]);
            $stockData = $getStock->fetch();
            $quantityBefore = (int)($stockData['quantity'] ?? 0);
            $initialQty = (int)($stockData['initial_quantity'] ?? 0);
            
            // Insert batch
            $stmt = $pdo->prepare('
                INSERT INTO stock_batches (product_id, batch_number, manufacturing_date, expiry_date, quantity) 
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$productId, $batchNumber, $manufacturingDate, $expiryDate, $quantity]);
            $batchId = (int)$pdo->lastInsertId();
            
            // Calculate new quantity
            $quantityAfter = $quantityBefore + $quantity;
            
            // IMPORTANT: Set initial quantity if this is the first stock addition
            if ($initialQty === 0 && $quantityBefore === 0) {
                // First time adding stock - set initial = quantity being added
                $updateStock = $pdo->prepare('
                    UPDATE stock 
                    SET quantity = ?, initial_quantity = ?
                    WHERE product_id = ?
                ');
                $updateStock->execute([$quantityAfter, $quantity, $productId]);
            } else {
                // Restocking - only update quantity, keep initial_quantity
                $updateStock = $pdo->prepare('
                    UPDATE stock 
                    SET quantity = quantity + ? 
                    WHERE product_id = ?
                ');
                $updateStock->execute([$quantity, $productId]);
            }
            
            // Record stock movement
            $recordMovement = $pdo->prepare('
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $recordMovement->execute([
                $productId,
                'addition',
                $quantity,
                $quantityBefore,
                $quantityAfter,
                'batch',
                $batchId,
                "Added batch #{$batchNumber} (Expiry: {$expiryDate})",
                $_SESSION['user_id']
            ]);
            
            // Mark product as having expiry
            $pdo->prepare('UPDATE products SET has_expiry = 1 WHERE id = ?')->execute([$productId]);
            
            $pdo->commit();
            echo json_encode(['id' => $batchId, 'batch_id' => $batchId]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add batch: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'PUT') {
        // Only admins can update batches
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can update batches']);
            exit;
        }
        
        // Update batch quantity
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { 
            http_response_code(400); 
            echo json_encode(['error' => 'id is required']); 
            exit; 
        }
        
        $data = json_input();
        $quantity = (int)($data['quantity'] ?? 0);
        
        if ($quantity < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Quantity cannot be negative']);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            // Get current batch info
            $stmt = $pdo->prepare('SELECT product_id, quantity FROM stock_batches WHERE id = ?');
            $stmt->execute([$id]);
            $batch = $stmt->fetch();
            
            if (!$batch) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Batch not found']);
                exit;
            }
            
            $oldQuantity = (int)$batch['quantity'];
            $productId = (int)$batch['product_id'];
            $quantityDiff = $quantity - $oldQuantity;
            
            // Update batch quantity
            $stmt = $pdo->prepare('UPDATE stock_batches SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$quantity, $id]);
            
            // Update total stock quantity
            $updateStock = $pdo->prepare('UPDATE stock SET quantity = quantity + ? WHERE product_id = ?');
            $updateStock->execute([$quantityDiff, $productId]);
            
            $pdo->commit();
            echo json_encode(['updated' => $stmt->rowCount()]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update batch: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'DELETE') {
        // Only admins can delete batches
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can delete batches']);
            exit;
        }
        
        // Delete ALL expired batches at once
        if (isset($_GET['expired_all']) && $_GET['expired_all'] === 'true') {
            $pdo->beginTransaction();
            try {
                // Get all expired batches with their quantities
                $stmt = $pdo->query('
                    SELECT sb.id, sb.product_id, sb.quantity 
                    FROM stock_batches sb
                    JOIN products p ON sb.product_id = p.id
                    WHERE p.has_expiry = 1 AND julianday(sb.expiry_date) < julianday("now")
                ');
                $expiredBatches = $stmt->fetchAll();
                
                if (empty($expiredBatches)) {
                    $pdo->rollBack();
                    echo json_encode(['deleted' => 0, 'message' => 'No expired batches found']);
                    exit;
                }
                
                // Reduce stock for each expired batch
                $updateStock = $pdo->prepare('UPDATE stock SET quantity = MAX(0, quantity - ?) WHERE product_id = ?');
                $deleteBatch = $pdo->prepare('DELETE FROM stock_batches WHERE id = ?');
                
                foreach ($expiredBatches as $batch) {
                    $updateStock->execute([$batch['quantity'], $batch['product_id']]);
                    $deleteBatch->execute([$batch['id']]);
                }
                
                $pdo->commit();
                echo json_encode(['deleted' => count($expiredBatches)]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete expired batches: ' . $e->getMessage()]);
            }
            exit;
        }
        
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { 
            http_response_code(400); 
            echo json_encode(['error' => 'id is required']); 
            exit; 
        }
        
        $pdo->beginTransaction();
        try {
            // Get batch info before deletion
            $stmt = $pdo->prepare('SELECT product_id, quantity FROM stock_batches WHERE id = ?');
            $stmt->execute([$id]);
            $batch = $stmt->fetch();
            
            if (!$batch) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Batch not found']);
                exit;
            }
            
            $productId = (int)$batch['product_id'];
            $quantity = (int)$batch['quantity'];
            
            // Delete batch
            $stmt = $pdo->prepare('DELETE FROM stock_batches WHERE id = ?');
            $stmt->execute([$id]);
            
            // Update total stock quantity
            $updateStock = $pdo->prepare('UPDATE stock SET quantity = quantity - ? WHERE product_id = ?');
            $updateStock->execute([$quantity, $productId]);
            
            $pdo->commit();
            echo json_encode(['deleted' => 1]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete batch: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error: ' . $e->getMessage()]);
}
