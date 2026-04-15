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
        // Optional query params: q (search), page, pageSize, count_only, id (single product)
        $q = $_GET['q'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $countOnly = isset($_GET['count_only']) && $_GET['count_only'] === '1';
        $singleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        // If only count is needed (for dashboard), return just the total
        if ($countOnly) {
            if ($q !== '') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE (name LIKE :q OR sku LIKE :q) AND id != 0 AND sku != "DELETED"');
                $stmt->bindValue(':q', '%' . $q . '%');
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE id != 0 AND sku != "DELETED"');
            }
            $stmt->execute();
            echo json_encode(['total' => (int)$stmt->fetchColumn()]);
            exit;
        }
        
        // If fetching a single product by ID
        if ($singleId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND id != 0 AND sku != "DELETED"');
            $stmt->execute([$singleId]);
            $product = $stmt->fetch();
            if (!$product) {
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
                exit;
            }
            $unitsStmt = $pdo->prepare('SELECT * FROM product_units WHERE product_id = ? ORDER BY is_base_unit DESC, unit_name ASC');
            $unitsStmt->execute([$product['id']]);
            $product['units'] = $unitsStmt->fetchAll();
            echo json_encode(['item' => $product]);
            exit;
        }

        if ($q !== '') {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE (name LIKE :q OR sku LIKE :q) AND id != 0 AND sku != "DELETED" ORDER BY id DESC LIMIT :limit OFFSET :offset');
            $stmt->bindValue(':q', '%' . $q . '%');
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id != 0 AND sku != "DELETED" ORDER BY id DESC LIMIT :limit OFFSET :offset');
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        
        $products = $stmt->fetchAll();
        
        // Get total count for pagination
        if ($q !== '') {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE (name LIKE :q OR sku LIKE :q) AND id != 0 AND sku != "DELETED"');
            $countStmt->bindValue(':q', '%' . $q . '%');
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE id != 0 AND sku != "DELETED"');
        }
        $countStmt->execute();
        $totalItems = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalItems / $pageSize);
        
        // Fetch units for each product
        $unitsStmt = $pdo->prepare('SELECT * FROM product_units WHERE product_id = ? ORDER BY is_base_unit DESC, unit_name ASC');
        foreach ($products as &$product) {
            $unitsStmt->execute([$product['id']]);
            $product['units'] = $unitsStmt->fetchAll();
        }
        
        echo json_encode([
            'items' => $products,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total_items' => $totalItems,
                'total_pages' => $totalPages
            ]
        ]);
        exit;
    }

    if ($method === 'POST') {
        $data = json_input();
        $sku = trim((string)($data['sku'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $unit_price = (float)($data['unit_price'] ?? 0);
        $cost_price = (float)($data['cost_price'] ?? 0);
        $has_expiry = (int)($data['has_expiry'] ?? 0);
        if ($sku === '' || $name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'sku and name are required']);
            exit;
        }

        // Check for duplicate product name
        $checkName = $pdo->prepare('SELECT id, name FROM products WHERE LOWER(name) = LOWER(?) AND id != 0 AND sku != "DELETED"');
        $checkName->execute([$name]);
        $existingProduct = $checkName->fetch();
        
        if ($existingProduct) {
            http_response_code(409);
            echo json_encode([
                'error' => 'duplicate_name',
                'message' => 'A product with this name already exists',
                'existing_product' => $existingProduct['name']
            ]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO products (sku, name, unit_price, has_expiry) VALUES (?, ?, ?, ?)');
            $ins->execute([$sku, $name, $unit_price, $has_expiry]);
            $pid = (int)$pdo->lastInsertId();
            
            // Ensure stock row exists
            $stk = $pdo->prepare('INSERT INTO stock (product_id, quantity) VALUES (?, 0)');
            $stk->execute([$pid]);
            
            // Create default unit (base unit) with cost_price
            $unitStmt = $pdo->prepare('
                INSERT INTO product_units (product_id, unit_name, unit_abbreviation, conversion_factor, unit_price, cost_price, is_base_unit) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $unitStmt->execute([$pid, 'Unit', 'unit', 1.0, $unit_price, $cost_price, 1]);
            $defaultUnitId = (int)$pdo->lastInsertId();
            
            // Set default_unit_id in products table
            $pdo->prepare('UPDATE products SET default_unit_id = ? WHERE id = ?')->execute([$defaultUnitId, $pid]);
            
            $pdo->commit();
            echo json_encode(['id' => $pid]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create product: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { 
            http_response_code(400); 
            echo json_encode(['error' => 'id is required']); 
            exit; 
        }
        
        $data = json_input();
        $name = trim((string)($data['name'] ?? ''));
        $unit_price = (float)($data['unit_price'] ?? 0);
        $has_expiry = isset($data['has_expiry']) ? (int)$data['has_expiry'] : null;
        
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'name is required']);
            exit;
        }
        
        // Check for duplicate name (excluding current product)
        $checkName = $pdo->prepare('SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND id != ? AND sku != "DELETED"');
        $checkName->execute([$name, $id]);
        if ($checkName->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error' => 'duplicate_name', 'message' => 'A product with this name already exists']);
            exit;
        }
        
        try {
            if ($has_expiry !== null) {
                $stmt = $pdo->prepare('UPDATE products SET name = ?, unit_price = ?, has_expiry = ? WHERE id = ?');
                $stmt->execute([$name, $unit_price, $has_expiry, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE products SET name = ?, unit_price = ? WHERE id = ?');
                $stmt->execute([$name, $unit_price, $id]);
            }
            echo json_encode(['updated' => $stmt->rowCount()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update product: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'DELETE') {
        // Only admins can delete products
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can delete products']);
            exit;
        }
        
        $id = (int)($_GET['id'] ?? 0);
        $deleteAll = isset($_GET['all']) && $_GET['all'] === 'true';
        $force = isset($_GET['force']) && $_GET['force'] === 'true';
        
        // Delete ALL products
        if ($deleteAll) {
            try {
                $pdo->beginTransaction();
                // Disable foreign keys temporarily to allow cascade delete
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $pdo->exec('DELETE FROM credit_payments');
                $pdo->exec('DELETE FROM credit_sales');
                $pdo->exec('DELETE FROM receipt_items');
                $pdo->exec('DELETE FROM receipts');
                $pdo->exec('DELETE FROM stock_movements');
                $pdo->exec('DELETE FROM stock_batches');
                $pdo->exec('DELETE FROM product_units');
                $pdo->exec('DELETE FROM stock');
                $pdo->exec('DELETE FROM products WHERE id != 0 AND sku != "DELETED"');
                $pdo->exec('DELETE FROM sqlite_sequence WHERE name IN ("products","stock","stock_movements","stock_batches","product_units","receipts","receipt_items","credit_sales","credit_payments")');
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->commit();
                echo json_encode(['deleted' => 'all']);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $pdo->exec('PRAGMA foreign_keys = ON');
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete all products: ' . $e->getMessage()]);
            }
            exit;
        }
        
        if ($id <= 0) { 
            http_response_code(400); 
            echo json_encode(['error' => 'id is required']); 
            exit; 
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get product info before deletion for historical records
            $productStmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $productStmt->execute([$id]);
            $product = $productStmt->fetch();
            
            if (!$product) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
                exit;
            }
            
            if (!$force) {
                // Normal delete - check if referenced in receipt_items
                $chk = $pdo->prepare('SELECT COUNT(*) FROM receipt_items WHERE product_id = ?');
                $chk->execute([$id]);
                if ((int)$chk->fetchColumn() > 0) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['error' => 'Product is referenced in receipts']);
                    exit;
                }
            } else {
                // Force delete - preserve product name in receipt_items and point to deleted product placeholder
                // First, ensure we have a deleted product placeholder
                $placeholderStmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? OR name = ?');
                $placeholderStmt->execute(['DELETED', '[DELETED PRODUCT]']);
                $placeholder = $placeholderStmt->fetch();
                
                if (!$placeholder) {
                    // Create deleted product placeholder if it doesn't exist
                    $pdo->prepare('INSERT INTO products (sku, name, unit_price) VALUES (?, ?, ?)')->execute(['DELETED-' . time(), '[DELETED PRODUCT]', 0.00]);
                    $placeholderId = $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO stock (product_id, quantity) VALUES (?, 0)')->execute([$placeholderId]);
                } else {
                    $placeholderId = $placeholder['id'];
                }
                
                // Update receipt_items to preserve product name and point to placeholder
                $updateStmt = $pdo->prepare('UPDATE receipt_items SET product_name = ?, product_id = ? WHERE product_id = ?');
                $updateStmt->execute([$product['name'], $placeholderId, $id]);
            }
            
            // Delete stock row then product
            $pdo->prepare('DELETE FROM stock WHERE product_id = ?')->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
                exit;
            }
            
            $pdo->commit();
            echo json_encode(['deleted' => 1, 'force' => $force, 'product_name' => $product['name']]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete product: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error: ' . $e->getMessage()]);
}