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
        $since = $_GET['since'] ?? null; // ISO datetime for polling
        $query = 'SELECT r.id, r.invoice_number, r.customer_name, r.total, r.total_profit,
                         r.payment_method, r.created_at, r.created_by_username,
                         GROUP_CONCAT(ri.product_name || " x" || ri.quantity, ", ") as items
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
    if ($action === 'products') {
        $q = $_GET['q'] ?? '';
        $stmt = $pdo->prepare('
            SELECT p.id, p.name, p.sku,
                   GROUP_CONCAT(pu.id || "|" || pu.unit_name || "|" || pu.unit_price || "|" || pu.cost_price, "||") as units
            FROM products p
            LEFT JOIN product_units pu ON pu.product_id = p.id
            WHERE p.id != 0 AND p.sku != "DELETED"
              AND (? = "" OR p.name LIKE ? OR p.sku LIKE ?)
            GROUP BY p.id ORDER BY p.name ASC LIMIT 30
        ');
        $like = '%' . $q . '%';
        $stmt->execute([$q, $like, $like]);
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'update_price' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $unitId   = (int)($data['unit_id'] ?? 0);
        $newPrice = (float)($data['unit_price'] ?? 0);
        if ($unitId <= 0 || $newPrice <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); exit; }
        $pdo->prepare('UPDATE product_units SET unit_price = ? WHERE id = ?')->execute([$newPrice, $unitId]);
        // Sync price back to products table if this is the base unit (keeps POS search in sync)
        $pdo->prepare('UPDATE products SET unit_price = ? WHERE id = (SELECT product_id FROM product_units WHERE id = ? AND is_base_unit = 1)')->execute([$newPrice, $unitId]);
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
