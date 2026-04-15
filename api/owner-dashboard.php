<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();

function getOwnerPin(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'owner_pin'");
    $stmt->execute();
    return (string)($stmt->fetchColumn() ?: '');
}

$action = $_GET['action'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── PIN Verify ────────────────────────────────────────────────────
if ($action === 'verify_pin') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin  = trim((string)($data['pin'] ?? ''));
    $storedPin = getOwnerPin($pdo);
    if ($storedPin === '') { http_response_code(400); echo json_encode(['error' => 'No owner PIN set. Please set one in System Settings.']); exit; }
    if ($pin === $storedPin) { $_SESSION['owner_authenticated'] = true; echo json_encode(['success' => true]); }
    else { http_response_code(401); echo json_encode(['error' => 'Incorrect PIN']); }
    exit;
}

if (empty($_SESSION['owner_authenticated'])) { http_response_code(401); echo json_encode(['error' => 'unauthenticated']); exit; }

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

    // ── MAIN DASHBOARD ────────────────────────────────────────────
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
