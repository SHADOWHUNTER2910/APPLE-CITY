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
    // ── CREATE RECEIPT ────────────────────────────────────────────────
    if ($method === 'POST') {
        $data           = json_input();
        $invoice_number = trim((string)($data['invoice_number'] ?? ''));
        $customer_name  = trim((string)($data['customer_name'] ?? ''));
        $customer_phone = trim((string)($data['customer_phone'] ?? ''));
        $customer_address = trim((string)($data['customer_address'] ?? ''));
        $payment_method = trim((string)($data['payment_method'] ?? 'cash'));
        $payment_reference = trim((string)($data['payment_reference'] ?? ''));
        $company_name   = trim((string)($data['company_name'] ?? ''));
        $company_location = trim((string)($data['company_location'] ?? ''));
        $items          = $data['items'] ?? [];
        $discount       = (float)($data['discount'] ?? 0);
        $cash_received  = (float)($data['cash_received'] ?? 0);
        $change_given   = (float)($data['change_given'] ?? 0);
        $trade_in_id    = isset($data['trade_in_id']) && $data['trade_in_id'] ? (int)$data['trade_in_id'] : null;
        $trade_in_value = (float)($data['trade_in_value'] ?? 0);
        $trade_in_device = trim((string)($data['trade_in_device'] ?? ''));

        if ($invoice_number === '' || !is_array($items) || count($items) === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invoice_number and items are required']);
            exit;
        }

        // ── Validate & calculate totals ───────────────────────────────
        $subtotal  = 0.0;
        $totalCost = 0.0;

        foreach ($items as $idx => $it) {
            $pid   = (int)($it['product_id'] ?? 0);
            $qty   = (int)($it['quantity'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0);

            if ($pid <= 0 || $qty <= 0) {
                http_response_code(400);
                echo json_encode(['error' => "Invalid item at index {$idx}"]);
                exit;
            }

            $subtotal += $qty * $price;

            // Get cost price from products table
            $costStmt = $pdo->prepare('SELECT cost_price FROM products WHERE id = ?');
            $costStmt->execute([$pid]);
            $costPrice = (float)($costStmt->fetchColumn() ?? 0);
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
        }

        $total       = max(0.0, $subtotal - $discount - $trade_in_value);
        $totalProfit = $total - $totalCost;
        $profitMargin = ($subtotal - $discount) > 0 ? ($totalProfit / ($subtotal - $discount)) * 100 : 0;

        // ── Insert receipt & items ────────────────────────────────────
        $pdo->beginTransaction();
        try {
            $insR = $pdo->prepare('
                INSERT INTO receipts
                    (invoice_number, customer_name, customer_phone, customer_address,
                     company_name, company_location, subtotal, discount, total,
                     total_cost, total_profit, profit_margin,
                     cash_received, change_given, payment_method, payment_reference,
                     trade_in_id, trade_in_value, trade_in_device,
                     created_by, created_by_username)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ');
            $insR->execute([
                $invoice_number, $customer_name, $customer_phone, $customer_address,
                $company_name, $company_location, $subtotal, $discount, $total,
                $totalCost, $totalProfit, $profitMargin,
                $cash_received, $change_given, $payment_method, $payment_reference,
                $trade_in_id, $trade_in_value, $trade_in_device ?: null,
                $_SESSION['user_id'], $_SESSION['username']
            ]);
            $rid = (int)$pdo->lastInsertId();

            // If trade-in linked, mark it as completed and link to this receipt
            if ($trade_in_id) {
                $pdo->prepare('UPDATE trade_ins SET status = "completed", linked_receipt_id = ? WHERE id = ?')
                    ->execute([$rid, $trade_in_id]);
            }

            $insI    = $pdo->prepare('
                INSERT INTO receipt_items
                    (receipt_id, product_id, product_name, quantity, unit_price, total_price, cost_price, profit)
                VALUES (?,?,?,?,?,?,?,?)
            ');
            $updS    = $pdo->prepare('UPDATE stock SET quantity = quantity - ? WHERE product_id = ?');
            $getQty  = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ?');
            $recMov  = $pdo->prepare('
                INSERT INTO stock_movements
                    (product_id, movement_type, quantity, quantity_before, quantity_after,
                     reference_type, reference_id, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ');
            $getName = $pdo->prepare('SELECT name, cost_price FROM products WHERE id = ?');

            foreach ($items as $it) {
                $pid   = (int)$it['product_id'];
                $qty   = (int)$it['quantity'];
                $price = (float)$it['unit_price'];

                $getName->execute([$pid]);
                $prod      = $getName->fetch();
                $prodName  = $prod['name'] ?? 'Unknown';
                $costPrice = (float)($prod['cost_price'] ?? 0);

                $itemTotal = $price * $qty;
                $itemCost  = $costPrice * $qty;
                // Proportional discount share
                $itemDiscountShare = $subtotal > 0 ? ($itemTotal / $subtotal) * $discount : 0;
                $itemProfit = ($itemTotal - $itemCost) - $itemDiscountShare;

                $getQty->execute([$pid]);
                $qtyBefore = (int)($getQty->fetchColumn() ?? 0);
                $qtyAfter  = $qtyBefore - $qty;

                $insI->execute([$rid, $pid, $prodName, $qty, $price, $itemTotal, $costPrice, $itemProfit]);
                $updS->execute([$qty, $pid]);
                $recMov->execute([$pid, 'deduction', $qty, $qtyBefore, $qtyAfter, 'receipt', $rid, "Sold via receipt #{$invoice_number}", $_SESSION['user_id']]);
            }

            $pdo->commit();
            echo json_encode(['id' => $rid, 'receipt_id' => $rid, 'invoice_number' => $invoice_number]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            error_log("Receipt creation error: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to create receipt: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── GET RECEIPT(S) ────────────────────────────────────────────────
    if ($method === 'GET') {
        $receiptId = (int)($_GET['id'] ?? 0);

        if ($receiptId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM receipts WHERE id = ?');
            $stmt->execute([$receiptId]);
            $receipt = $stmt->fetch();
            if (!$receipt) { http_response_code(404); echo json_encode(['error' => 'Receipt not found']); exit; }

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

        // List receipts
        $searchQuery = $_GET['q'] ?? '';
        $filterType  = $_GET['filter_type'] ?? 'all';
        $startDate   = $_GET['start_date'] ?? '';
        $endDate     = $_GET['end_date'] ?? '';
        $userRole    = $_SESSION['role'] ?? 'user';
        $userId      = $_SESSION['user_id'];

        $baseQuery = '
            SELECT DISTINCT r.*,
                   GROUP_CONCAT(COALESCE(ri.product_name, p.name, "Deleted Product"), ", ") as products
            FROM receipts r
            LEFT JOIN receipt_items ri ON r.id = ri.receipt_id
            LEFT JOIN products p ON ri.product_id = p.id
        ';

        $conditions = [];
        $params     = [];

        if ($userRole !== 'admin') {
            $conditions[] = 'r.created_by = ?';
            $params[]     = $userId;
        }

        if ($searchQuery !== '') {
            switch ($filterType) {
                case 'product':
                    $conditions[] = '(COALESCE(ri.product_name, p.name, "") LIKE ?)';
                    $params[]     = '%' . $searchQuery . '%';
                    break;
                case 'customer':
                    $conditions[] = 'r.customer_name LIKE ?';
                    $params[]     = '%' . $searchQuery . '%';
                    break;
                case 'creator':
                    $conditions[] = 'r.created_by_username LIKE ?';
                    $params[]     = '%' . $searchQuery . '%';
                    break;
                case 'invoice':
                    $conditions[] = 'r.invoice_number LIKE ?';
                    $params[]     = '%' . $searchQuery . '%';
                    break;
                default:
                    $conditions[] = '(r.invoice_number LIKE ? OR r.customer_name LIKE ? OR r.created_by_username LIKE ? OR COALESCE(ri.product_name, p.name, "") LIKE ?)';
                    $params = array_merge($params, array_fill(0, 4, '%' . $searchQuery . '%'));
            }
        }

        if ($startDate !== '') { $conditions[] = 'DATE(r.created_at) >= ?'; $params[] = $startDate; }
        if ($endDate   !== '') { $conditions[] = 'DATE(r.created_at) <= ?'; $params[] = $endDate; }

        if (!empty($conditions)) $baseQuery .= ' WHERE ' . implode(' AND ', $conditions);
        $baseQuery .= ' GROUP BY r.id ORDER BY r.id DESC LIMIT 200';

        $stmt = $pdo->prepare($baseQuery);
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    // ── DELETE RECEIPT ────────────────────────────────────────────────
    if ($method === 'DELETE') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403); echo json_encode(['error' => 'Admin only']); exit;
        }
        $receiptId = (int)($_GET['id'] ?? 0);
        if ($receiptId <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $pdo->beginTransaction();
        try {
            // Restore stock
            $items = $pdo->prepare('SELECT product_id, quantity FROM receipt_items WHERE receipt_id = ?');
            $items->execute([$receiptId]);
            foreach ($items->fetchAll() as $item) {
                if ((int)$item['product_id'] > 0 && (int)$item['quantity'] > 0) {
                    $pdo->prepare('UPDATE stock SET quantity = quantity + ? WHERE product_id = ?')
                        ->execute([$item['quantity'], $item['product_id']]);
                }
            }
            $pdo->prepare('DELETE FROM stock_movements WHERE reference_type = "receipt" AND reference_id = ?')->execute([$receiptId]);
            $pdo->prepare('DELETE FROM receipt_items WHERE receipt_id = ?')->execute([$receiptId]);
            $stmt = $pdo->prepare('DELETE FROM receipts WHERE id = ?');
            $stmt->execute([$receiptId]);
            if ($stmt->rowCount() === 0) { $pdo->rollBack(); http_response_code(404); echo json_encode(['error' => 'Receipt not found']); exit; }
            $pdo->commit();
            echo json_encode(['deleted' => true]);
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
    echo json_encode(['error' => 'Unexpected server error: ' . $e->getMessage()]);
}
