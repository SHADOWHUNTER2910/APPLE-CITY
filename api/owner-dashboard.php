<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();

$action = $_GET['action'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Auth: require admin session ───────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

try {
    // ── LIVE RECEIPT FEED ─────────────────────────────────────────
    if ($action === 'live_receipts') {
        $limit = (int)($_GET['limit'] ?? 20);
        $since = $_GET['since'] ?? null;
        $query = 'SELECT r.id, r.invoice_number, r.customer_name, r.total, r.total_profit,
                         r.payment_method, r.trade_in_value, r.trade_in_device,
                         r.created_at, r.created_by_username,
                         GROUP_CONCAT(
                             ri.product_name ||
                             CASE WHEN ri.variant_label IS NOT NULL AND ri.variant_label != "" THEN " (" || ri.variant_label || ")" ELSE "" END ||
                             CASE WHEN ri.imei IS NOT NULL THEN " [" || ri.imei || "]" ELSE "" END,
                             ", "
                         ) as items
                  FROM receipts r
                  LEFT JOIN receipt_items ri ON ri.receipt_id = r.id';
        $params = [];
        if ($since) { $query .= ' WHERE r.created_at > ?'; $params[] = $since; }
        $query .= ' GROUP BY r.id ORDER BY r.created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    // ── DAILY P&L ─────────────────────────────────────────────────
    if ($action === 'pnl') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as receipts,
                   COALESCE(SUM(total),0) as revenue,
                   COALESCE(SUM(total_cost),0) as cost,
                   COALESCE(SUM(total_profit),0) as profit,
                   COALESCE(SUM(discount),0) as total_discount,
                   CASE WHEN SUM(total)>0 THEN ROUND((SUM(total_profit)/SUM(total))*100,2) ELSE 0 END as margin,
                   SUM(CASE WHEN payment_method="cash" THEN total ELSE 0 END) as cash_sales,
                   SUM(CASE WHEN payment_method="mobile_money" THEN total ELSE 0 END) as momo_sales,
                   SUM(CASE WHEN payment_method="credit" THEN total ELSE 0 END) as credit_sales,
                   SUM(CASE WHEN payment_method="card" THEN total ELSE 0 END) as card_sales
            FROM receipts WHERE DATE(created_at) = ?
        ');
        $stmt->execute([$date]);
        $summary = $stmt->fetch();

        $stmt = $pdo->prepare('
            SELECT COALESCE(created_by_username,"Unknown") as username,
                   COUNT(*) as receipts, SUM(total) as sales, SUM(total_profit) as profit
            FROM receipts WHERE DATE(created_at) = ?
            GROUP BY created_by ORDER BY sales DESC
        ');
        $stmt->execute([$date]);
        $by_staff = $stmt->fetchAll();

        $stmt = $pdo->prepare('
            SELECT COALESCE(ri.product_name,"Unknown") as name,
                   SUM(ri.quantity) as qty, SUM(ri.total_price) as revenue, SUM(ri.profit) as profit
            FROM receipt_items ri JOIN receipts r ON ri.receipt_id = r.id
            WHERE DATE(r.created_at) = ?
            GROUP BY ri.product_id, ri.product_name ORDER BY revenue DESC LIMIT 10
        ');
        $stmt->execute([$date]);
        $top_products = $stmt->fetchAll();

        echo json_encode(['date' => $date, 'summary' => $summary, 'by_staff' => $by_staff, 'top_products' => $top_products]);
        exit;
    }

    // ── PRICE CONTROL ─────────────────────────────────────────────
    // Shows products with their IMEI units (storage/color/price per unit)
    if ($action === 'products') {
        $q = $_GET['q'] ?? '';
        $like = '%' . $q . '%';
        // Get products
        $stmt = $pdo->prepare('
            SELECT p.id, p.name, p.sku
            FROM products p
            WHERE p.id != 0 AND p.sku != "DELETED"
              AND (? = "" OR p.name LIKE ? OR p.sku LIKE ?)
            ORDER BY p.name ASC LIMIT 30
        ');
        $stmt->execute([$q, $like, $like]);
        $products = $stmt->fetchAll();

        // For each product, get its in-stock IMEI units with variant info
        $unitStmt = $pdo->prepare('
            SELECT u.id, u.imei, u.storage, u.color, u.selling_price, u.cost_price
            FROM imei_units u
            WHERE u.product_id = ? AND u.status = "in_stock"
            ORDER BY u.storage, u.color, u.selling_price
        ');
        foreach ($products as &$p) {
            $unitStmt->execute([$p['id']]);
            $p['imei_units'] = $unitStmt->fetchAll();
        }
        unset($p);
        echo json_encode(['items' => $products]);
        exit;
    }

    // Update selling price on a specific IMEI unit
    if ($action === 'update_imei_price' && $method === 'POST') {
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $imeiId   = (int)($data['imei_id'] ?? 0);
        $newPrice = (float)($data['selling_price'] ?? 0);
        if ($imeiId <= 0 || $newPrice <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); exit; }
        $pdo->prepare('UPDATE imei_units SET selling_price = ? WHERE id = ?')->execute([$newPrice, $imeiId]);
        // Also update the variant selling price if this unit has one
        $pdo->prepare('UPDATE product_variants SET selling_price = ? WHERE id = (SELECT variant_id FROM imei_units WHERE id = ?)')->execute([$newPrice, $imeiId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Legacy: keep update_price for backward compat but now updates product base price
    if ($action === 'update_price' && $method === 'POST') {
        $data      = json_decode(file_get_contents('php://input'), true) ?? [];
        $productId = (int)($data['product_id'] ?? 0);
        $newPrice  = (float)($data['unit_price'] ?? 0);
        if ($productId <= 0 || $newPrice <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); exit; }
        $pdo->prepare('UPDATE products SET unit_price = ? WHERE id = ?')->execute([$newPrice, $productId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── REPAIRS SUMMARY ───────────────────────────────────────────
    if ($action === 'repairs') {
        $stmt = $pdo->query('
            SELECT
                COUNT(CASE WHEN status NOT IN ("collected","cancelled") THEN 1 END) as active,
                COUNT(CASE WHEN status = "ready" THEN 1 END) as ready,
                COUNT(CASE WHEN status = "collected" THEN 1 END) as collected_total,
                COALESCE(SUM(CASE WHEN DATE(created_at) = DATE("now") THEN total_charge ELSE 0 END),0) as revenue_today,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= DATE("now","-30 days") THEN total_charge ELSE 0 END),0) as revenue_30d
            FROM repairs
        ');
        $summary = $stmt->fetch();

        $stmt = $pdo->query('
            SELECT id, job_number, customer_name, device_model, imei, status,
                   total_charge, payment_status, created_at
            FROM repairs
            WHERE status NOT IN ("collected","cancelled")
            ORDER BY
                CASE status WHEN "ready" THEN 0 ELSE 1 END,
                created_at ASC
            LIMIT 20
        ');
        $active_jobs = $stmt->fetchAll();
        echo json_encode(['summary' => $summary, 'active_jobs' => $active_jobs]);
        exit;
    }

    // ── TRADE-INS SUMMARY ─────────────────────────────────────────
    if ($action === 'trade_ins') {
        $stmt = $pdo->query('
            SELECT
                COUNT(CASE WHEN status="pending" THEN 1 END) as pending,
                COUNT(CASE WHEN status="completed" THEN 1 END) as completed,
                COALESCE(SUM(CASE WHEN DATE(created_at) = DATE("now") THEN agreed_value ELSE 0 END),0) as value_today,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= DATE("now","-30 days") THEN agreed_value ELSE 0 END),0) as value_30d
            FROM trade_ins
        ');
        $summary = $stmt->fetch();

        $stmt = $pdo->query('
            SELECT id, customer_name, device_model, imei, condition,
                   agreed_value, status, added_to_inventory, created_at
            FROM trade_ins
            ORDER BY created_at DESC LIMIT 15
        ');
        $items = $stmt->fetchAll();
        echo json_encode(['summary' => $summary, 'items' => $items]);
        exit;
    }

    // ── WARRANTIES SUMMARY ────────────────────────────────────────
    if ($action === 'warranties') {
        $stmt = $pdo->query('
            SELECT
                COUNT(CASE WHEN end_date >= DATE("now") AND status != "claimed" THEN 1 END) as active,
                COUNT(CASE WHEN end_date < DATE("now") THEN 1 END) as expired,
                COUNT(CASE WHEN status = "claimed" THEN 1 END) as claimed,
                COUNT(CASE WHEN end_date >= DATE("now") AND end_date <= DATE("now","+30 days") AND status != "claimed" THEN 1 END) as expiring_soon
            FROM warranties
        ');
        $summary = $stmt->fetch();

        // Expiring soon
        $stmt = $pdo->query('
            SELECT imei, product_name, customer_name, end_date,
                   CAST((julianday(end_date) - julianday("now")) AS INTEGER) as days_left
            FROM warranties
            WHERE end_date >= DATE("now") AND end_date <= DATE("now","+30 days") AND status != "claimed"
            ORDER BY end_date ASC LIMIT 10
        ');
        $expiring = $stmt->fetchAll();
        echo json_encode(['summary' => $summary, 'expiring_soon' => $expiring]);
        exit;
    }

    // Bulk update selling price for all in-stock IMEI units of a product+variant
    if ($action === 'update_imei_variant_price' && $method === 'POST') {
        $data       = json_decode(file_get_contents('php://input'), true) ?? [];
        $productId  = (int)($data['product_id'] ?? 0);
        $variantKey = trim((string)($data['variant_key'] ?? ''));
        $newPrice   = (float)($data['selling_price'] ?? 0);
        if ($productId <= 0 || $newPrice <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); exit; }

        // Parse variant key "storage / color"
        $parts   = explode(' / ', $variantKey, 2);
        $storage = trim($parts[0] ?? '');
        $color   = trim($parts[1] ?? '');
        $storageVal = $storage === '—' ? null : $storage;
        $colorVal   = $color   === '—' ? null : $color;

        // Update all in-stock IMEI units matching this product + variant
        $pdo->prepare('UPDATE imei_units SET selling_price = ? WHERE product_id = ? AND status = "in_stock"
                        AND (storage = ? OR (storage IS NULL AND ? IS NULL))
                        AND (color = ? OR (color IS NULL AND ? IS NULL))')
            ->execute([$newPrice, $productId, $storageVal, $storageVal, $colorVal, $colorVal]);

        // Also update the product_variants table
        $pdo->prepare('UPDATE product_variants SET selling_price = ? WHERE product_id = ?
                        AND (storage = ? OR (storage IS NULL AND ? IS NULL))
                        AND (color = ? OR (color IS NULL AND ? IS NULL))')
            ->execute([$newPrice, $productId, $storageVal, $storageVal, $colorVal, $colorVal]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── CASHIER CONTROL ───────────────────────────────────────────
    if ($action === 'staff') {
        $stmt = $pdo->query('SELECT id, username, role, COALESCE(status,"active") as status, created_at FROM users ORDER BY role DESC, username ASC');
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'toggle_staff' && $method === 'POST') {
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int)($data['user_id'] ?? 0);
        $status = ($data['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        if ($userId <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid user']); exit; }
        // Prevent locking out all admins
        if ($status === 'inactive') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role="admin" AND COALESCE(status,"active")="active" AND id != ?');
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() === 0) { http_response_code(400); echo json_encode(['error' => 'Cannot disable the last active admin']); exit; }
        }
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);
        echo json_encode(['success' => true, 'new_status' => $status]);
        exit;
    }

    // ── ALERTS TAB ────────────────────────────────────────────────
    if ($action === 'alerts') {
        $today = date('Y-m-d');
        $alerts = [];

        // 1. Large sale alerts (receipts over GH₵500 today)
        $stmt = $pdo->prepare('SELECT invoice_number, customer_name, total, created_by_username, created_at FROM receipts WHERE DATE(created_at) = ? AND total >= 500 ORDER BY total DESC');
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll() as $r) {
            $alerts[] = ['type'=>'large_sale','severity'=>'warning','title'=>'Large Sale: '.$r['invoice_number'],'detail'=>$r['customer_name'].' — GH₵'.number_format($r['total'],2).' by '.$r['created_by_username'],'time'=>$r['created_at']];
        }

        // 2. New credit sales today
        $stmt = $pdo->prepare('SELECT cs.invoice_number, c.name as customer, cs.amount_owed, cs.sale_date FROM credit_sales cs LEFT JOIN customers c ON c.id = cs.customer_id WHERE DATE(cs.sale_date) = ? ORDER BY cs.sale_date DESC');
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll() as $r) {
            $alerts[] = ['type'=>'credit_sale','severity'=>'danger','title'=>'Credit Sale: '.$r['invoice_number'],'detail'=>($r['customer']??'Unknown').' owes GH₵'.number_format($r['amount_owed'],2),'time'=>$r['sale_date']];
        }

        // 2b. Debt payments received today
        $stmt = $pdo->prepare('SELECT cp.amount, cp.payment_method, cp.paid_at, c.name as customer, cs.invoice_number, cs.status FROM credit_payments cp JOIN credit_sales cs ON cp.credit_sale_id = cs.id LEFT JOIN customers c ON cp.customer_id = c.id WHERE DATE(cp.paid_at) = ? ORDER BY cp.paid_at DESC');
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll() as $r) {
            $fullyPaid = $r['status'] === 'paid';
            $alerts[] = ['type'=>'debt_paid','severity'=>'info','title'=>($fullyPaid ? '✅ Debt Fully Settled: ' : '💵 Partial Payment: ').$r['invoice_number'],'detail'=>($r['customer']??'Unknown').' paid GH₵'.number_format($r['amount'],2).' via '.$r['payment_method'].($fullyPaid?' — FULLY PAID':' — partial'),'time'=>$r['paid_at']];
        }

        // 3. No-sale gap (2+ hours with no receipts today)
        $stmt = $pdo->prepare('SELECT created_at FROM receipts WHERE DATE(created_at) = ? ORDER BY created_at ASC');
        $stmt->execute([$today]);
        $times = array_column($stmt->fetchAll(), 'created_at');
        for ($i = 1; $i < count($times); $i++) {
            $gap = (strtotime($times[$i]) - strtotime($times[$i-1])) / 3600;
            if ($gap >= 2) {
                $alerts[] = ['type'=>'no_sale_gap','severity'=>'danger','title'=>round($gap,1).'h gap with no sales','detail'=>'From '.date('H:i',strtotime($times[$i-1])).' to '.date('H:i',strtotime($times[$i])),'time'=>$times[$i-1]];
            }
        }
        // Check last sale to now
        if (!empty($times)) {
            $hoursSinceLast = (time() - strtotime(end($times))) / 3600;
            if ($hoursSinceLast >= 2) {
                $alerts[] = ['type'=>'no_sale_gap','severity'=>'danger','title'=>round($hoursSinceLast,1).'h since last sale','detail'=>'Last sale at '.date('H:i',strtotime(end($times))),'time'=>end($times)];
            }
        }

        // 4. Stock variance (stock dropped without receipt today)
        $stmt = $pdo->prepare('SELECT sm.quantity, p.name, u.username FROM stock_movements sm JOIN products p ON sm.product_id = p.id LEFT JOIN users u ON sm.created_by = u.id WHERE DATE(sm.created_at) = ? AND sm.movement_type = "deduction" AND (sm.reference_type = "manual" OR sm.reference_type IS NULL) ORDER BY sm.created_at DESC LIMIT 10');
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll() as $r) {
            $alerts[] = ['type'=>'stock_variance','severity'=>'danger','title'=>'Manual stock deduction: '.$r['name'],'detail'=>$r['quantity'].' units removed by '.($r['username']??'Unknown').' without a receipt','time'=>$today];
        }

        // 5. Overdue debts (credit sales unpaid for 7+ days)
        $stmt = $pdo->query('SELECT c.name, cs.invoice_number, cs.balance, cs.sale_date FROM credit_sales cs LEFT JOIN customers c ON c.id = cs.customer_id WHERE cs.status != "paid" AND cs.balance > 0 AND julianday("now") - julianday(cs.sale_date) >= 7 ORDER BY cs.balance DESC LIMIT 10');
        foreach ($stmt->fetchAll() as $r) {
            $days = (int)((time() - strtotime($r['sale_date'])) / 86400);
            $alerts[] = ['type'=>'overdue_debt','severity'=>'warning','title'=>'Overdue debt: '.($r['name']??'Unknown'),'detail'=>'GH₵'.number_format($r['balance'],2).' unpaid for '.$days.' days ('.$r['invoice_number'].')','time'=>$r['sale_date']];
        }

        // Sort by severity then time
        usort($alerts, fn($a,$b) => ($a['severity']==='danger'?0:1) - ($b['severity']==='danger'?0:1));
        echo json_encode(['alerts' => $alerts, 'count' => count($alerts)]);
        exit;
    }

    // ── CASH RECONCILIATION ───────────────────────────────────────
    if ($action === 'cash_recon') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(CASE WHEN payment_method="cash" THEN total ELSE 0 END),0) as expected_cash,
                   COALESCE(SUM(CASE WHEN payment_method="mobile_money" THEN total ELSE 0 END),0) as momo,
                   COALESCE(SUM(CASE WHEN payment_method="card" THEN total ELSE 0 END),0) as card,
                   COALESCE(SUM(CASE WHEN payment_method="credit" THEN total ELSE 0 END),0) as credit,
                   COUNT(*) as total_receipts,
                   COALESCE(SUM(total),0) as total_revenue
            FROM receipts WHERE DATE(created_at) = ?
        ');
        $stmt->execute([$date]);
        echo json_encode($stmt->fetch());
        exit;
    }

    // ── CREDIT LIMITS ─────────────────────────────────────────────
    if ($action === 'customers') {
        $stmt = $pdo->query('SELECT c.id, c.name, c.phone, c.credit_limit, COALESCE(SUM(CASE WHEN cs.status!="paid" THEN cs.balance ELSE 0 END),0) as total_debt FROM customers c LEFT JOIN credit_sales cs ON cs.customer_id = c.id GROUP BY c.id ORDER BY total_debt DESC LIMIT 50');
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'update_credit_limit' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $cid   = (int)($data['customer_id'] ?? 0);
        $limit = (float)($data['credit_limit'] ?? 0);
        if ($cid <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid customer']); exit; }
        $pdo->prepare('UPDATE customers SET credit_limit = ? WHERE id = ?')->execute([$limit, $cid]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE SUSPICIOUS RECEIPT ─────────────────────────────────
    if ($action === 'delete_receipt' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $rid  = (int)($data['receipt_id'] ?? 0);
        if ($rid <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid receipt']); exit; }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM receipt_items WHERE receipt_id = ?')->execute([$rid]);
        $pdo->prepare('DELETE FROM receipts WHERE id = ?')->execute([$rid]);
        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    // ── STAFF ACTIVITY LOG ────────────────────────────────────────
    if ($action === 'staff_activity') {
        $date = $_GET['date'] ?? date('Y-m-d');
        // Sales activity per staff
        $stmt = $pdo->prepare('
            SELECT created_by_username as username,
                   MIN(created_at) as first_sale,
                   MAX(created_at) as last_sale,
                   COUNT(*) as receipts,
                   SUM(total) as total_sales
            FROM receipts WHERE DATE(created_at) = ?
            GROUP BY created_by ORDER BY first_sale ASC
        ');
        $stmt->execute([$date]);
        $activity = $stmt->fetchAll();

        // Stock movements by staff today
        $stmt = $pdo->prepare('
            SELECT u.username, sm.movement_type, COUNT(*) as count, SUM(sm.quantity) as total_qty
            FROM stock_movements sm LEFT JOIN users u ON sm.created_by = u.id
            WHERE DATE(sm.created_at) = ?
            GROUP BY sm.created_by, sm.movement_type ORDER BY u.username
        ');
        $stmt->execute([$date]);
        $movements = $stmt->fetchAll();

        echo json_encode(['activity' => $activity, 'movements' => $movements, 'date' => $date]);
        exit;
    }
    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT COUNT(*) as receipts, COALESCE(SUM(total),0) as revenue, COALESCE(SUM(total_profit),0) as profit, COALESCE(SUM(total_cost),0) as cost, CASE WHEN SUM(total)>0 THEN ROUND((SUM(total_profit)/SUM(total))*100,1) ELSE 0 END as margin FROM receipts WHERE DATE(created_at) = ?');
    $stmt->execute([$today]); $today_summary = $stmt->fetch();

    // Repair revenue today
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(total_charge),0) as repair_revenue, COUNT(*) as repair_count FROM repairs WHERE DATE(created_at) = ? AND payment_status = "paid"');
    $stmt->execute([$today]); $repair_today = $stmt->fetch();
    $today_summary['repair_revenue'] = (float)$repair_today['repair_revenue'];
    $today_summary['repair_count']   = (int)$repair_today['repair_count'];

    // Active repairs count
    $stmt = $pdo->query('SELECT COUNT(*) FROM repairs WHERE status NOT IN ("collected","cancelled")');
    $today_summary['active_repairs'] = (int)$stmt->fetchColumn();
    $stmt = $pdo->query('SELECT COUNT(*) FROM repairs WHERE status = "ready"');
    $today_summary['ready_repairs'] = (int)$stmt->fetchColumn();

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) as revenue, COALESCE(SUM(total_profit),0) as profit FROM receipts WHERE DATE(created_at) = ?');
    $stmt->execute([$yesterday]); $yesterday_summary = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT DATE(created_at) as date, COUNT(*) as receipts, COALESCE(SUM(total),0) as revenue, COALESCE(SUM(total_profit),0) as profit FROM receipts WHERE DATE(created_at) >= ? GROUP BY DATE(created_at) ORDER BY date ASC');
    $stmt->execute([date('Y-m-d', strtotime('-6 days'))]); $weekly_trend = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT p.name, s.quantity FROM stock s JOIN products p ON s.product_id = p.id WHERE p.id != 0 AND p.sku != "DELETED" AND s.quantity > 0 AND s.quantity < 10 ORDER BY s.quantity ASC LIMIT 10');
    $low_stock = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT p.name FROM stock s JOIN products p ON s.product_id = p.id WHERE p.id != 0 AND p.sku != "DELETED" AND s.quantity = 0 ORDER BY p.name ASC LIMIT 10');
    $out_of_stock = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT COALESCE(SUM(balance),0) as total FROM credit_sales WHERE status != "paid"');
    $total_debt = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COALESCE(ri.product_name,"Unknown") as name, SUM(ri.quantity) as qty, SUM(ri.total_price) as revenue FROM receipt_items ri JOIN receipts r ON ri.receipt_id = r.id WHERE DATE(r.created_at) = ? GROUP BY ri.product_id, ri.product_name ORDER BY qty DESC LIMIT 5');
    $stmt->execute([$today]); $top_products = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT invoice_number, customer_name, total, payment_method, created_at, created_by_username FROM receipts ORDER BY created_at DESC LIMIT 10');
    $recent_receipts = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT COALESCE(created_by_username,"Unknown") as username, COUNT(*) as receipts, COALESCE(SUM(total),0) as sales FROM receipts WHERE DATE(created_at) = ? GROUP BY created_by ORDER BY sales DESC');
    $stmt->execute([$today]); $staff_today = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM company_settings");
    $settings = []; foreach ($stmt->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];

    echo json_encode(['today' => $today_summary, 'yesterday' => $yesterday_summary, 'weekly_trend' => $weekly_trend, 'low_stock' => $low_stock, 'out_of_stock' => $out_of_stock, 'total_debt' => $total_debt, 'top_products' => $top_products, 'recent_receipts' => $recent_receipts, 'staff_today' => $staff_today, 'company' => $settings, 'generated_at' => date('Y-m-d H:i:s')]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
