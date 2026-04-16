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
        
        if ($productId) {
            // Get units for a specific product
            $stmt = $pdo->prepare('
                SELECT * FROM product_units 
                WHERE product_id = ? 
                ORDER BY is_base_unit DESC, unit_name ASC
            ');
            $stmt->execute([$productId]);
            echo json_encode(['items' => $stmt->fetchAll()]);
        } else {
            // Get all units
            $stmt = $pdo->query('
                SELECT pu.*, p.name as product_name 
                FROM product_units pu
                JOIN products p ON pu.product_id = p.id
                ORDER BY p.name, pu.is_base_unit DESC, pu.unit_name ASC
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
        }
        exit;
    }

    if ($method === 'POST') {
        // Only admins can add units
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can add units']);
            exit;
        }
        
        $data = json_input();
        $productId = (int)($data['product_id'] ?? 0);
        $unitName = trim((string)($data['unit_name'] ?? ''));
        $unitAbbr = trim((string)($data['unit_abbreviation'] ?? ''));
        $conversionFactor = (float)($data['conversion_factor'] ?? 1.0);
        $unitPrice = (float)($data['unit_price'] ?? 0.0);
        $costPrice = (float)($data['cost_price'] ?? 0.0);
        $isBaseUnit = (int)($data['is_base_unit'] ?? 0);
        
        if ($productId <= 0 || $unitName === '' || $unitAbbr === '') {
            http_response_code(400);
            echo json_encode(['error' => 'product_id, unit_name, and unit_abbreviation are required']);
            exit;
        }
        
        if ($conversionFactor <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'conversion_factor must be positive']);
            exit;
        }
        
        if ($unitPrice < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'unit_price cannot be negative']);
            exit;
        }
        
        if ($costPrice < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'cost_price cannot be negative']);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            // If this is being set as base unit, unset other base units for this product
            if ($isBaseUnit) {
                $pdo->prepare('UPDATE product_units SET is_base_unit = 0 WHERE product_id = ?')->execute([$productId]);
            }
            
            // Check if this is the first unit for the product
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM product_units WHERE product_id = ?');
            $checkStmt->execute([$productId]);
            $unitCount = (int)$checkStmt->fetchColumn();
            
            // If first unit, force it to be base unit
            if ($unitCount === 0) {
                $isBaseUnit = 1;
            }
            
            // Insert the unit
            $stmt = $pdo->prepare('
                INSERT INTO product_units (product_id, unit_name, unit_abbreviation, conversion_factor, unit_price, cost_price, is_base_unit) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$productId, $unitName, $unitAbbr, $conversionFactor, $unitPrice, $costPrice, $isBaseUnit]);
            $unitId = (int)$pdo->lastInsertId();
            
            // If this is base unit, update product's default_unit_id
            if ($isBaseUnit) {
                $pdo->prepare('UPDATE products SET default_unit_id = ? WHERE id = ?')->execute([$unitId, $productId]);
            }
            
            $pdo->commit();
            echo json_encode(['id' => $unitId, 'unit_id' => $unitId]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add unit: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'PUT') {
        // Only admins can update units
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can update units']);
            exit;
        }
        
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { 
            http_response_code(400); 
            echo json_encode(['error' => 'id is required']); 
            exit; 
        }
        
        $data = json_input();
        
        $pdo->beginTransaction();
        try {
            // Get current unit info
            $stmt = $pdo->prepare('SELECT product_id, is_base_unit FROM product_units WHERE id = ?');
            $stmt->execute([$id]);
            $unit = $stmt->fetch();
            
            if (!$unit) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Unit not found']);
                exit;
            }
            
            $productId = (int)$unit['product_id'];
            $fields = [];
            $params = [];
            
            if (isset($data['unit_name'])) {
                $fields[] = 'unit_name = ?';
                $params[] = trim((string)$data['unit_name']);
            }
            
            if (isset($data['unit_abbreviation'])) {
                $fields[] = 'unit_abbreviation = ?';
                $params[] = trim((string)$data['unit_abbreviation']);
            }
            
            if (isset($data['conversion_factor'])) {
                $conversionFactor = (float)$data['conversion_factor'];
                if ($conversionFactor <= 0) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'conversion_factor must be positive']);
                    exit;
                }
                $fields[] = 'conversion_factor = ?';
                $params[] = $conversionFactor;
            }
            
            if (isset($data['unit_price'])) {
                $unitPrice = (float)$data['unit_price'];
                if ($unitPrice < 0) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'unit_price cannot be negative']);
                    exit;
                }
                $fields[] = 'unit_price = ?';
                $params[] = $unitPrice;
            }
            
            if (isset($data['cost_price'])) {
                $costPrice = (float)$data['cost_price'];
                if ($costPrice < 0) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'cost_price cannot be negative']);
                    exit;
                }
                $fields[] = 'cost_price = ?';
                $params[] = $costPrice;
            }
            
            if (isset($data['is_base_unit'])) {
                $isBaseUnit = (int)$data['is_base_unit'];
                if ($isBaseUnit) {
                    // Unset other base units for this product
                    $pdo->prepare('UPDATE product_units SET is_base_unit = 0 WHERE product_id = ?')->execute([$productId]);
                }
                $fields[] = 'is_base_unit = ?';
                $params[] = $isBaseUnit;
            }
            
            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            
            if (!empty($fields)) {
                $params[] = $id;
                $stmt = $pdo->prepare('UPDATE product_units SET ' . implode(', ', $fields) . ' WHERE id = ?');
                $stmt->execute($params);
            }
            
            // If this unit is now base unit, update product's default_unit_id
            if (isset($data['is_base_unit']) && $data['is_base_unit']) {
                $pdo->prepare('UPDATE products SET default_unit_id = ? WHERE id = ?')->execute([$id, $productId]);
            }

            // If unit_price changed and this is the base unit, sync to products.unit_price
            if (isset($data['unit_price'])) {
                $checkBase = $pdo->prepare('SELECT is_base_unit FROM product_units WHERE id = ?');
                $checkBase->execute([$id]);
                if ((int)$checkBase->fetchColumn() === 1) {
                    $pdo->prepare('UPDATE products SET unit_price = ? WHERE id = ?')->execute([(float)$data['unit_price'], $productId]);
                }
            }

            $pdo->commit();
            echo json_encode(['updated' => 1]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update unit: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($method === 'DELETE') {
        // Only admins can delete units
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only administrators can delete units']);
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
            // Get unit info
            $stmt = $pdo->prepare('SELECT product_id, is_base_unit FROM product_units WHERE id = ?');
            $stmt->execute([$id]);
            $unit = $stmt->fetch();
            
            if (!$unit) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Unit not found']);
                exit;
            }
            
            // Check if this is the base unit
            if ($unit['is_base_unit']) {
                // Check if there are other units for this product
                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM product_units WHERE product_id = ? AND id != ?');
                $checkStmt->execute([$unit['product_id'], $id]);
                $otherUnits = (int)$checkStmt->fetchColumn();
                
                if ($otherUnits > 0) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['error' => 'Cannot delete base unit while other units exist. Set another unit as base first.']);
                    exit;
                }
            }
            
            // Check if unit is referenced in receipts
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM receipt_items WHERE unit_id = ?');
            $checkStmt->execute([$id]);
            $receiptCount = (int)$checkStmt->fetchColumn();
            
            if ($receiptCount > 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => 'Cannot delete unit that is referenced in receipts']);
                exit;
            }
            
            // Delete the unit
            $stmt = $pdo->prepare('DELETE FROM product_units WHERE id = ?');
            $stmt->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['deleted' => 1]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete unit: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error: ' . $e->getMessage()]);
}
