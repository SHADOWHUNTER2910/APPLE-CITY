<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

// Only admins can bulk import
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Only administrators can bulk import products']);
    exit;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function generateSKU(PDO $pdo, string $productName): string {
    // Generate SKU from product name
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $productName), 0, 3));
    if (strlen($prefix) < 3) {
        $prefix = str_pad($prefix, 3, 'X');
    }
    
    // Find next available number
    $stmt = $pdo->prepare("SELECT sku FROM products WHERE sku LIKE ? ORDER BY sku DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $lastSku = $stmt->fetchColumn();
    
    if ($lastSku) {
        $number = (int)substr($lastSku, 3) + 1;
    } else {
        $number = 1;
    }
    
    return $prefix . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
}

function generateBatchNumber(): string {
    // Format: BATCH-YYYYMMDD-HHMMSS-RRR
    $now = new DateTime();
    $year = $now->format('Y');
    $month = $now->format('m');
    $day = $now->format('d');
    $hours = $now->format('H');
    $minutes = $now->format('i');
    $seconds = $now->format('s');
    $random = str_pad((string)rand(0, 999), 3, '0', STR_PAD_LEFT);
    
    return "BATCH-{$year}{$month}{$day}-{$hours}{$minutes}{$seconds}-{$random}";
}

try {
    if ($method === 'POST') {
        $data = json_input();
        $productList = trim((string)($data['product_list'] ?? ''));
        
        if ($productList === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Product list is required']);
            exit;
        }
        
        // Parse product list
        // NEW SIMPLIFIED FORMAT: "Product Name, Cost Price, Selling Price, Quantity, Expiry Date"
        // - Batch numbers are auto-generated
        // - Manufacturing date is set to today
        // - If Expiry Date is provided, product has expiry; if empty, no expiry
        $lines = explode("\n", $productList);
        $products = [];
        $errors = [];
        $lineNumber = 0;
        
        foreach ($lines as $line) {
            $lineNumber++;
            $line = trim($line);
            
            // Skip empty lines
            if ($line === '') {
                continue;
            }
            
            // Parse line - 5 fields: Name, Cost, Selling, Quantity, Expiry Date
            $parts = array_map('trim', explode(',', $line));
            
            if (count($parts) < 4) {
                $errors[] = "Line {$lineNumber}: Invalid format. Required: 'Product Name, Cost Price, Selling Price, Quantity, Expiry Date (optional)'";
                continue;
            }
            
            $name = $parts[0];
            $costPrice = $parts[1];
            $sellingPrice = $parts[2];
            $quantity = isset($parts[3]) && $parts[3] !== '' ? $parts[3] : '0';
            $expiryDate = isset($parts[4]) && $parts[4] !== '' ? $parts[4] : '';
            
            // Determine if product has expiry based on whether expiry date is provided
            $hasExpiryValue = $expiryDate !== '' ? 1 : 0;
            
            // Auto-generate batch number if product has expiry
            $batchNumber = $hasExpiryValue === 1 ? generateBatchNumber() : '';
            
            // Set manufacturing date to today if product has expiry
            $mfgDate = $hasExpiryValue === 1 ? date('Y-m-d') : '';
            
            // Validate product name
            if ($name === '') {
                $errors[] = "Line {$lineNumber}: Product name is required";
                continue;
            }
            
            // Validate cost price
            if (!is_numeric($costPrice) || (float)$costPrice < 0) {
                $errors[] = "Line {$lineNumber}: Invalid cost price '{$costPrice}'. Must be a positive number";
                continue;
            }
            
            // Validate selling price
            if (!is_numeric($sellingPrice) || (float)$sellingPrice < 0) {
                $errors[] = "Line {$lineNumber}: Invalid selling price '{$sellingPrice}'. Must be a positive number";
                continue;
            }
            
            // Validate quantity
            if (!is_numeric($quantity) || (int)$quantity < 0) {
                $errors[] = "Line {$lineNumber}: Invalid quantity '{$quantity}'. Must be a positive number";
                continue;
            }
            
            // If expiry date provided, validate it
            if ($hasExpiryValue === 1) {
                // Validate expiry date format (YYYY-MM-DD)
                $dateObj = DateTime::createFromFormat('Y-m-d', $expiryDate);
                if (!$dateObj || $dateObj->format('Y-m-d') !== $expiryDate) {
                    $errors[] = "Line {$lineNumber}: Invalid expiry date '{$expiryDate}'. Use format YYYY-MM-DD (e.g., 2027-12-31)";
                    continue;
                }
            }
            
            $products[] = [
                'name' => $name,
                'cost_price' => (float)$costPrice,
                'selling_price' => (float)$sellingPrice,
                'quantity' => (int)$quantity,
                'has_expiry' => $hasExpiryValue,
                'batch_number' => $batchNumber,
                'mfg_date' => $mfgDate,
                'expiry_date' => $expiryDate,
                'line' => $lineNumber
            ];
        }
        
        // If there are validation errors, return them
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Validation failed',
                'errors' => $errors,
                'valid_count' => count($products),
                'error_count' => count($errors)
            ]);
            exit;
        }
        
        // If no products to import
        if (empty($products)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid products found in the list']);
            exit;
        }
        
        // Check if this is a resolution pass (user has made decisions on duplicates)
        $resolutions = isset($data['resolutions']) ? $data['resolutions'] : null;
        
        // Separate duplicates from new products
        $checkDuplicate = $pdo->prepare('SELECT id, name, unit_price FROM products WHERE LOWER(name) = LOWER(?) AND sku != "DELETED"');
        $newProducts = [];
        $duplicates = [];
        
        foreach ($products as $product) {
            $checkDuplicate->execute([$product['name']]);
            $existing = $checkDuplicate->fetch();
            if ($existing) {
                $product['existing_id'] = $existing['id'];
                $product['existing_price'] = $existing['unit_price'];
                $duplicates[] = $product;
            } else {
                $newProducts[] = $product;
            }
        }
        
        // If duplicates found and no resolutions provided yet, return duplicates for user decision
        if (!empty($duplicates) && $resolutions === null) {
            echo json_encode([
                'has_duplicates' => true,
                'duplicates' => array_map(fn($d) => [
                    'name' => $d['name'],
                    'cost_price' => $d['cost_price'],
                    'selling_price' => $d['selling_price'],
                    'quantity' => $d['quantity'],
                    'expiry_date' => $d['expiry_date'],
                    'has_expiry' => $d['has_expiry'],
                    'batch_number' => $d['batch_number'],
                    'mfg_date' => $d['mfg_date'],
                    'existing_id' => $d['existing_id'],
                    'line' => $d['line']
                ], $duplicates),
                'new_count' => count($newProducts),
                'duplicate_count' => count($duplicates)
            ]);
            exit;
        }
        
        // Apply resolutions to duplicates
        if ($resolutions !== null) {
            foreach ($duplicates as $dup) {
                $resolution = $resolutions[$dup['name']] ?? ['action' => 'skip'];
                $action = $resolution['action'] ?? 'skip';
                
                if ($action === 'update') {
                    // Keep in products list with existing_id for update
                    $dup['resolution'] = 'update';
                    $newProducts[] = $dup;
                } elseif ($action === 'rename') {
                    // Add as new product with new name
                    $newName = trim($resolution['new_name'] ?? '');
                    if ($newName !== '') {
                        $dup['name'] = $newName;
                        $dup['resolution'] = 'rename';
                        unset($dup['existing_id']);
                        $newProducts[] = $dup;
                    }
                }
                // 'skip' = do nothing
            }
        }
        
        // Import products in a transaction
        $pdo->beginTransaction();
        
        try {
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $importErrors = [];
            
            $insertProduct = $pdo->prepare('INSERT INTO products (sku, name, unit_price, has_expiry) VALUES (?, ?, ?, ?)');
            $insertStock = $pdo->prepare('INSERT INTO stock (product_id, quantity, initial_quantity) VALUES (?, ?, ?)');
            $insertUnit = $pdo->prepare('INSERT INTO product_units (product_id, unit_name, unit_abbreviation, conversion_factor, unit_price, cost_price, is_base_unit) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $updateDefaultUnit = $pdo->prepare('UPDATE products SET default_unit_id = ? WHERE id = ?');
            $insertMovement = $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            
            foreach ($newProducts as $product) {
                try {
                    $isUpdate = isset($product['resolution']) && $product['resolution'] === 'update';
                    
                    if ($isUpdate) {
                        // Update existing product price and add stock
                        $existingId = $product['existing_id'];
                        $pdo->prepare('UPDATE products SET unit_price = ? WHERE id = ?')
                            ->execute([$product['selling_price'], $existingId]);
                        
                        // Update base unit price and cost
                        $pdo->prepare('UPDATE product_units SET unit_price = ?, cost_price = ? WHERE product_id = ? AND is_base_unit = 1')
                            ->execute([$product['selling_price'], $product['cost_price'], $existingId]);
                        
                        // Add stock quantity
                        if ($product['quantity'] > 0) {
                            $getStock = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ?');
                            $getStock->execute([$existingId]);
                            $currentQty = (int)($getStock->fetchColumn() ?? 0);
                            $newQty = $currentQty + $product['quantity'];
                            
                            $pdo->prepare('UPDATE stock SET quantity = ? WHERE product_id = ?')
                                ->execute([$newQty, $existingId]);
                            
                            $insertMovement->execute([
                                $existingId, 'addition', $product['quantity'],
                                $currentQty, $newQty, 'manual', null,
                                "Stock updated via bulk import", $_SESSION['user_id']
                            ]);
                        }
                        $updated++;
                        continue;
                    }
                    
                    // Insert new product
                    $sku = generateSKU($pdo, $product['name']);
                    $insertProduct->execute([$sku, $product['name'], $product['selling_price'], $product['has_expiry']]);
                    $productId = (int)$pdo->lastInsertId();
                    
                    $quantity = $product['quantity'];
                    $insertStock->execute([$productId, $quantity, $quantity]);
                    
                    $insertUnit->execute([$productId, 'Unit', 'unit', 1.0, $product['selling_price'], $product['cost_price'], 1]);
                    $defaultUnitId = (int)$pdo->lastInsertId();
                    $updateDefaultUnit->execute([$defaultUnitId, $productId]);
                    
                    if ($product['has_expiry'] === 1 && $quantity > 0) {
                        $mfgDateValue = $product['mfg_date'] !== '' ? $product['mfg_date'] : null;
                        $insertBatch = $pdo->prepare('INSERT INTO stock_batches (product_id, batch_number, manufacturing_date, expiry_date, quantity) VALUES (?, ?, ?, ?, ?)');
                        $insertBatch->execute([$productId, $product['batch_number'], $mfgDateValue, $product['expiry_date'], $quantity]);
                        $batchId = (int)$pdo->lastInsertId();
                        $insertMovement->execute([$productId, 'addition', $quantity, 0, $quantity, 'batch', $batchId, "Initial stock via bulk import - Batch: {$product['batch_number']}", $_SESSION['user_id']]);
                    } elseif ($quantity > 0) {
                        $insertMovement->execute([$productId, 'addition', $quantity, 0, $quantity, 'manual', null, "Initial stock via bulk import", $_SESSION['user_id']]);
                    }
                    
                    $imported++;
                    
                } catch (Throwable $e) {
                    $importErrors[] = "Line {$product['line']}: Failed to import '{$product['name']}' - " . $e->getMessage();
                    $skipped++;
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'total' => count($newProducts) + count($duplicates ?? []),
                'errors' => $importErrors
            ]);
            
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Bulk import failed: ' . $e->getMessage()]);
        }
        
        exit;
    }
    
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error: ' . $e->getMessage()]);
}
