<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

$pdo = get_pdo();

// ── PIN Authentication ────────────────────────────────────────────
// Owner sets a PIN in System Settings. Stored as 'owner_pin' in company_settings.
// Session-based: once verified, stays authenticated for the session.

function getOwnerPin(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'owner_pin'");
    $stmt->execute();
    return (string)($stmt->fetchColumn() ?: '');
}

$action = $_GET['action'] ?? 'dashboard';

// ── PIN Verify ────────────────────────────────────────────────────
if ($action === 'verify_pin') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin  = trim((string)($data['pin'] ?? ''));
    $storedPin = getOwnerPin($pdo);

    if ($storedPin === '') {
        http_response_code(400);
        echo json_encode(['error' => 'No owner PIN set. Please set one in System Settings.']);
        exit;
    }

    if ($pin === $storedPin) {
        $_SESSION['owner_authenticated'] = true;
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Incorrect PIN']);
    }
    exit;
}

// ── Check auth ────────────────────────────────────────────────────
if (empty($_SESSION['owner_authenticated'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

// ── Dashboard Data ────────────────────────────────────────────────
try {
    $today = date('Y-m-d');

    // Today's summary
    $stmt = $pdo->prepare('
        SELECT COUNT(*) as receipts,
               COALESCE(SUM(total),0) as revenue,
               COALESCE(SUM(total_profit),0) as profit,
               COALESCE(SUM(total_cost),0) as cost,
               CASE WHEN SUM(total)>0 THEN ROUND((SUM(total_profit)/SUM(total))*100,1) ELSE 0 END as margin
        FROM receipts WHERE DATE(created_at) = ?
    ');
    $stmt->execute([$today]);
    $today_summary = $stmt->fetch();

    // Yesterday comparison
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) as revenue, COALESCE(SUM(total_profit),0) as profit FROM receipts WHERE DATE(created_at) = ?');
    $stmt->execute([$yesterday]);
    $yesterday_summary = $stmt->fetch();

    // Last 7 days trend
    $stmt = $pdo->prepare('
        SELECT DATE(created_at) as date,
               COUNT(*) as receipts,
               COALESCE(SUM(total),0) as revenue,
               COALESCE(SUM(total_profit),0) as profit
        FROM receipts WHERE DATE(created_at) >= ?
        GROUP BY DATE(created_at) ORDER BY date ASC
    ');
    $stmt->execute([date('Y-m-d', strtotime('-6 days'))]);
    $weekly_trend = $stmt->fetchAll();

    // Low stock alerts
    $stmt = $pdo->query('
        SELECT p.name, s.quantity
        FROM stock s JOIN products p ON s.product_id = p.id
        WHERE p.id != 0 AND p.sku != "DELETED" AND s.quantity > 0 AND s.quantity < 10
        ORDER BY s.quantity ASC LIMIT 10
    ');
    $low_stock = $stmt->fetchAll();

    // Out of stock
    $stmt = $pdo->query('
        SELECT p.name FROM stock s JOIN products p ON s.product_id = p.id
        WHERE p.id != 0 AND p.sku != "DELETED" AND s.quantity = 0
        ORDER BY p.name ASC LIMIT 10
    ');
    $out_of_stock = $stmt->fetchAll();

    // Outstanding debt
    $stmt = $pdo->query('SELECT COALESCE(SUM(balance),0) as total FROM credit_sales WHERE status != "paid"');
    $total_debt = (float)$stmt->fetchColumn();

    // Top 5 products today
    $stmt = $pdo->prepare('
        SELECT COALESCE(ri.product_name,"Unknown") as name,
               SUM(ri.quantity) as qty, SUM(ri.total_price) as revenue
        FROM receipt_items ri JOIN receipts r ON ri.receipt_id = r.id
        WHERE DATE(r.created_at) = ?
        GROUP BY ri.product_id, ri.product_name
        ORDER BY qty DESC LIMIT 5
    ');
    $stmt->execute([$today]);
    $top_products = $stmt->fetchAll();

    // Recent receipts (last 5)
    $stmt = $pdo->query('
        SELECT invoice_number, customer_name, total, payment_method, created_at
        FROM receipts ORDER BY created_at DESC LIMIT 5
    ');
    $recent_receipts = $stmt->fetchAll();

    // Sales by staff today
    $stmt = $pdo->prepare('
        SELECT COALESCE(created_by_username,"Unknown") as username,
               COUNT(*) as receipts, COALESCE(SUM(total),0) as sales
        FROM receipts WHERE DATE(created_at) = ?
        GROUP BY created_by ORDER BY sales DESC
    ');
    $stmt->execute([$today]);
    $staff_today = $stmt->fetchAll();

    // Company info
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM company_settings");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];

    echo json_encode([
        'today'            => $today_summary,
        'yesterday'        => $yesterday_summary,
        'weekly_trend'     => $weekly_trend,
        'low_stock'        => $low_stock,
        'out_of_stock'     => $out_of_stock,
        'total_debt'       => $total_debt,
        'top_products'     => $top_products,
        'recent_receipts'  => $recent_receipts,
        'staff_today'      => $staff_today,
        'company'          => $settings,
        'generated_at'     => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
