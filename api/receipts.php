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
    if ($method === 'POST') {
        $data = json_input();
        // Expected payload: { invoice_number, customer_name, customer_phone, customer_address, company_name, company_location, items: [{product_id, quantity, unit_price}] }
        $invoice_number = trim((string)($data['invoice_number'] ?? ''));
        $customer_name = trim((string)($data['customer_name'] ?? ''));
        $customer_phone = trim((string)($data['customer_phone'] ?? ''));
        $customer_address = trim((string)($data['customer_address'] ?? ''));
        $payment_method = trim((string)($data['payment_method'] ?? 'cash'));
        $payment_reference = trim((string)($data['payment_reference'] ?? ''));
        $dealer_name = trim((string)($data['dealer_name'] ?? ''));
        $company_name = trim((string)($data['company_name'] ?? ''));
        $company_location = trim((string)($data['company_location'] ?? ''));
        $items = $data['items'] ?? [];
        if ($invoice_number === '' || !is_array($items) || count($items) === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invoice_number and items are required']);
            exit;
        }

        // Calculate totals and validate stock
        $subtotal = 0.0;
        $totalCost = 0.0;
        $discount = (float)($data['discount'] ?? 0);
        
        foreach ($items as $idx => $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0);
            $unitId = isset($it['unit_id']) ? (int)$it['unit_id'] : null;
            
            if ($pid <= 0 || $qty <= 0) {
                http_response_code(400);
                echo json_encode(['error' => "Invalid item at index {$idx}"]); exit;
            }
            $subtotal += $qty * $price;
            
            // Get unit cost price for profit calculation
            $costPrice = 0.0;
            if ($unitId) {
                $costStmt = $pdo->prepare('SELECT cost_price FROM product_units WHERE id = ?');
                $costStmt->execute([$unitId]);
                $costPrice = (float)($costStmt->fetchColumn() ?? 0);
            }
            $totalCost += $qty * $costPrice;
            
            // Check available stock
            $st = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ?');
            $st->execute([$pid]);
            $available = (int)($st->fetchColumn() ?? 0);
            if ($available < $qty) {
                http_response_code(409);
                echo json_encode(['error' => 'Insufficient stock', 'product_id' => $pid, 'available' => $available, 'requested' => $qty]);
                exit;
            }
            
            // Check if product has expiry and all stock is expired
            $expiryCheck = $pdo->prepare('SELECT has_expiry FROM products WHERE id = ?');
            $expiryCheck->execute([$pid]);
            $hasExpiry = (int)($expiryCheck->fetchColumn() ?? 0);
            
            if ($hasExpiry === 1) {
                // Check if there are ANY batches for this product
                $batchCountStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_batches WHERE product_id = ?');
                $batchCountStmt->execute([$pid]);
                $batchCount = (int)$batchCountStmt->fetchColumn();
                
                // Only block if there are batches AND all are expired
                // If no batches exist, allow the sale (stock was added without batch tracking)
                if ($batchCount > 0) {
                    // Check if there is any non-expired stock with quantity
                    $validStockStmt = $pdo->prepare('
                        SELECT COALESCE(SUM(quantity), 0) 
                        FROM stock_batches 
                        WHERE product_id = ? 
                        AND julianday(expiry_date) >= julianday("now")
                        AND quantity > 0
                    ');
                    $validStockStmt->execute([$pid]);
                    $validStock = (int)$validStockStmt->fetchColumn();
                    
                    if ($validStock <= 0) {
                        http_response_code(409);
                        $productNameStmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                        $productNameStmt->execute([$pid]);
                        $productName = $productNameStmt->fetchColumn();
                        echo json_encode([
                            'error' => 'expired_stock',
                            'message' => "Cannot sell \"{$productName}\" - all stock has expired. Please remove expired batches first."
                        ]);
                        exit;
                    }
                    
                    // Check if requested quantity exceeds valid (non-expired) stock
                    if ($validStock < $qty) {
                        http_response_code(409);
                        $productNameStmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                        $productNameStmt->execute([$pid]);
                        $productName = $productNameStmt->fetchColumn();
                        echo json_encode([
                            'error' => 'insufficient_valid_stock',
                            'message' => "Only {$validStock} non-expired units available for \"{$productName}\". Requested: {$qty}"
                        ]);
                        exit;
                    }
                }
            }
        }
        // Apply discount to total
        $total = max(0, $subtotal - $discount);
        $totalProfit = $total - $totalCost;
        $profitMargin = $total > 0 ? ($totalProfit / $total) * 100 : 0;

        try {
            $pdo->beginTransaction();
            // Create receipt
            $cash_received = isset($data['cash_received']) ? (float)$data['cash_received'] : $total;
            $change_given = isset($data['change_given']) ? (float)$data['change_given'] : 0;
            
            $insR = $pdo->prepare('INSERT INTO receipts (invoice_number, customer_name, customer_phone, customer_address, dealer_name, company_name, company_location, subtotal, discount, total, total_cost, total_profit, profit_margin, cash_received, change_given, payment_method, payment_reference, created_by, created_by_username) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insR->execute([$invoice_number, $customer_name, $customer_phone, $customer_address, $dealer_name, $company_name, $company_location, $subtotal, $discount, $total, $totalCost, $totalProfit, $profitMargin, $cash_received, $change_given, $payment_method, $payment_reference, $_SESSION['user_id'], $_SESSION['username']]);
            $rid = (int)$pdo->lastInsertId();

            // Insert items and deduct stock using FIFO for products with expiry
            $insI = $pdo->prepare('INSERT INTO receipt_items (receipt_id, product_id, product_name, quantity, unit_price, total_price, cost_price, profit, batch_id, expiry_date, unit_id, unit_name, unit_abbreviation, quantity_in_base_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $updS = $pdo->prepare('UPDATE stock SET quantity = quantity - ? WHERE product_id = ?');
            $getStock = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ?');
            $recordMovement = $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $getProductInfo = $pdo->prepare('SELECT name, has_expiry FROM products WHERE id = ?');
            $getBatches = $pdo->prepare('SELECT id, batch_number, expiry_date, quantity FROM stock_batches WHERE product_id = ? AND quantity > 0 ORDER BY expiry_date ASC');
            $updateBatch = $pdo->prepare('UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?');
            $getUnit = $pdo->prepare('SELECT unit_name, unit_abbreviation, conversion_factor, cost_price FROM product_units WHERE id = ?');
            
            // Calculate discount ratio for proportional profit adjustment in analytics
            $discountRatio = $subtotal > 0 ? ($discount / $subtotal) : 0;
            
            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity'];
                $price = (float)$it['unit_price'];
                $unitId = isset($it['unit_id']) ? (int)$it['unit_id'] : null;
                
                // Get current stock quantity before deduction
                $getStock->execute([$pid]);
                $quantityBefore = (int)($getStock->fetchColumn() ?? 0);
                
                // Get product info
                $getProductInfo->execute([$pid]);
                $productInfo = $getProductInfo->fetch();
                $productName = $productInfo['name'];
                $hasExpiry = (int)$productInfo['has_expiry'];
                
                // Get unit info and cost price
                $unitName = null;
                $unitAbbr = null;
                $costPrice = 0.0;
                $quantityInBaseUnit = $qty;
                
                if ($unitId) {
                    $getUnit->execute([$unitId]);
                    $unitInfo = $getUnit->fetch();
                    if ($unitInfo) {
                        $unitName = $unitInfo['unit_name'];
                        $unitAbbr = $unitInfo['unit_abbreviation'];
                        $conversionFactor = (float)$unitInfo['conversion_factor'];
                        $costPrice = (float)($unitInfo['cost_price'] ?? 0);
                        $quantityInBaseUnit = $qty * $conversionFactor;
                    }
                }
                
                // Store original item price (discount is at receipt level, not item level)
                $itemTotal = $price * $qty;
                
                // Adjust profit by deducting this item's proportional share of the discount
                $itemCost = $costPrice * $qty;
                $itemGrossProfit = $itemTotal - $itemCost;
                $itemDiscountShare = $subtotal > 0 ? ($itemTotal / $subtotal) * $discount : 0;
                $itemProfit = $itemGrossProfit - $itemDiscountShare;
                
                // Handle products with expiry using FIFO
                if ($hasExpiry) {
                    $getBatches->execute([$pid]);
                    $batches = $getBatches->fetchAll();
                    
                    if (empty($batches)) {
                        throw new Exception("Product '{$productName}' has expiry tracking but no batches available");
                    }
                    
                    $remainingQty = $quantityInBaseUnit;
                    
                    // Deduct from batches in FIFO order (oldest expiry first)
                    foreach ($batches as $batch) {
                        if ($remainingQty <= 0) break;
                        
                        $batchId = (int)$batch['id'];
                        $batchQty = (int)$batch['quantity'];
                        $expiryDate = $batch['expiry_date'];
                        
                        // Calculate how much to take from this batch
                        $qtyFromBatch = min($remainingQty, $batchQty);
                        
                        // Insert receipt item with batch info and discounted profit
                        $insI->execute([$rid, $pid, $productName, $qty, $price, $qty * $price, $costPrice, $itemProfit, $batchId, $expiryDate, $unitId, $unitName, $unitAbbr, $quantityInBaseUnit]);
                        
                        // Deduct from batch
                        $updateBatch->execute([$qtyFromBatch, $batchId]);
                        
                        // Auto-delete batch if quantity reaches 0
                        $newBatchQty = $batchQty - $qtyFromBatch;
                        if ($newBatchQty <= 0) {
                            $deleteBatch = $pdo->prepare('DELETE FROM stock_batches WHERE id = ?');
                            $deleteBatch->execute([$batchId]);
                        }
                        
                        $remainingQty -= $qtyFromBatch;
                    }
                    
                    if ($remainingQty > 0) {
                        throw new Exception("Insufficient batch quantity for product '{$productName}'. Needed {$quantityInBaseUnit} (base units), available in batches: " . ($quantityInBaseUnit - $remainingQty));
                    }
                } else {
                    // Products without expiry - store original price, profit adjusted for discount
                    $insI->execute([$rid, $pid, $productName, $qty, $price, $qty * $price, $costPrice, $itemProfit, null, null, $unitId, $unitName, $unitAbbr, $quantityInBaseUnit]);
                }
                
                // Update total stock quantity (always in base units)
                $updS->execute([$quantityInBaseUnit, $pid]);
                
                // Get quantity after deduction
                $quantityAfter = $quantityBefore - $quantityInBaseUnit;
                
                // Record stock movement
                $recordMovement->execute([
                    $pid,
                    'deduction',
                    $quantityInBaseUnit,
                    $quantityBefore,
                    $quantityAfter,
                    'receipt',
                    $rid,
                    "Sold via receipt #{$invoice_number}",
                    $_SESSION['user_id']
                ]);
            }

            $pdo->commit();
            echo json_encode(['id' => $rid, 'receipt_id' => $rid]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            // Add more detailed error for debugging
            error_log("Receipt creation error: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to create receipt: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'GET') {
        // Check if requesting a specific receipt by ID
        $receiptId = (int)($_GET['id'] ?? 0);
        
        if ($receiptId > 0) {
            // Get specific receipt with items
            $stmt = $pdo->prepare('SELECT * FROM receipts WHERE id = ?');
            $stmt->execute([$receiptId]);
            $receipt = $stmt->fetch();
            
            if (!$receipt) {
                http_response_code(404);
                echo json_encode(['error' => 'Receipt not found']);
                exit;
            }
            
            // Get receipt items with product names (handle deleted products)
            $itemsStmt = $pdo->prepare('
                SELECT ri.*, 
                       COALESCE(ri.product_name, p.name, "Deleted Product") as product_name,
                       COALESCE(p.sku, "N/A") as sku 
                FROM receipt_items ri 
                LEFT JOIN products p ON ri.product_id = p.id 
                WHERE ri.receipt_id = ?
            ');
            $itemsStmt->execute([$receiptId]);
            $receipt['items'] = $itemsStmt->fetchAll();
            
            echo json_encode($receipt);
            exit;
        }
        
        // List receipts with optional filters
        $searchQuery = $_GET['q'] ?? '';
        $filterType = $_GET['filter_type'] ?? 'all'; // all, product, customer, creator, date
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        
        // Filter receipts based on user role
        $userRole = $_SESSION['role'] ?? 'user';
        $userId = $_SESSION['user_id'];
        
        // Build query with product names
        $baseQuery = '
            SELECT DISTINCT r.*,
                   GROUP_CONCAT(COALESCE(ri.product_name, p.name, "Deleted Product"), ", ") as products
            FROM receipts r
            LEFT JOIN receipt_items ri ON r.id = ri.receipt_id
            LEFT JOIN products p ON ri.product_id = p.id
        ';
        
        $conditions = [];
        $params = [];
        
        // Role-based filtering
        if ($userRole !== 'admin') {
            $conditions[] = 'r.created_by = ?';
            $params[] = $userId;
        }
        
        // Search filtering
        if ($searchQuery !== '') {
            switch ($filterType) {
                case 'product':
                    $conditions[] = '(COALESCE(ri.product_name, p.name, "") LIKE ? OR p.sku LIKE ?)';
                    $params[] = '%' . $searchQuery . '%';
                    $params[] = '%' . $searchQuery . '%';
                    break;
                case 'customer':
                    $conditions[] = 'r.customer_name LIKE ?';
                    $params[] = '%' . $searchQuery . '%';
                    break;
                case 'creator':
                    $conditions[] = 'r.created_by_username LIKE ?';
                    $params[] = '%' . $searchQuery . '%';
                    break;
                case 'invoice':
                    $conditions[] = 'r.invoice_number LIKE ?';
                    $params[] = '%' . $searchQuery . '%';
                    break;
                default: // 'all'
                    $conditions[] = '(r.invoice_number LIKE ? OR r.customer_name LIKE ? OR r.created_by_username LIKE ? OR COALESCE(ri.product_name, p.name, "") LIKE ?)';
                    $params[] = '%' . $searchQuery . '%';
                    $params[] = '%' . $searchQuery . '%';
                    $params[] = '%' . $searchQuery . '%';
                    $params[] = '%' . $searchQuery . '%';
            }
        }
        
        // Date range filtering
        if ($startDate !== '') {
            $conditions[] = 'DATE(r.created_at) >= ?';
            $params[] = $startDate;
        }
        if ($endDate !== '') {
            $conditions[] = 'DATE(r.created_at) <= ?';
            $params[] = $endDate;
        }
        
        // Combine conditions
        if (!empty($conditions)) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $conditions);
        }
        
        $baseQuery .= ' GROUP BY r.id ORDER BY r.id DESC LIMIT 100';
        
        $stmt = $pdo->prepare($baseQuery);
        $stmt->execute($params);
        $receipts = $stmt->fetchAll();
        
        echo json_encode(['items' => $receipts]);
        exit;
    }

    if ($method === 'DELETE') {
        // Only admins can delete receipts
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can delete receipts']);
            exit;
        }
        
        $receiptId = (int)($_GET['id'] ?? 0);
        if ($receiptId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Receipt ID is required']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();

            // Get receipt items to restore stock before deleting
            $items = $pdo->prepare('SELECT product_id, quantity_in_base_unit, quantity FROM receipt_items WHERE receipt_id = ?');
            $items->execute([$receiptId]);
            $receiptItems = $items->fetchAll();

            // Restore stock for each item
            foreach ($receiptItems as $item) {
                $pid = (int)$item['product_id'];
                $qty = (float)($item['quantity_in_base_unit'] ?? $item['quantity']);
                if ($pid <= 0 || $qty <= 0) continue;

                // Restore stock quantity
                $pdo->prepare('UPDATE stock SET quantity = quantity + ? WHERE product_id = ?')->execute([$qty, $pid]);
            }

            // Delete stock movements linked to this receipt
            $pdo->prepare('DELETE FROM stock_movements WHERE reference_type = "receipt" AND reference_id = ?')->execute([$receiptId]);

            // Delete receipt items
            $pdo->prepare('DELETE FROM receipt_items WHERE receipt_id = ?')->execute([$receiptId]);

            // Delete the receipt
            $stmt = $pdo->prepare('DELETE FROM receipts WHERE id = ?');
            $stmt->execute([$receiptId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Receipt not found']);
                exit;
            }

            $pdo->commit();
            echo json_encode(['deleted' => true, 'message' => 'Receipt deleted and stock restored successfully']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete receipt: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error']);
}