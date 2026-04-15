<?php
declare(strict_types=1);
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$pdo = get_pdo();

try {
    $action = $_GET['action'] ?? 'variance';
    $days   = max(1, (int)($_GET['days'] ?? 30));
    $since  = date('Y-m-d', strtotime("-{$days} days"));

    // ── 1. STOCK VARIANCE REPORT ─────────────────────────────────────
    // Expected = initial_quantity + restock_additions - receipt_deductions
    // The initial stock addition movement is already captured in initial_quantity
    // so we only count additions that happened AFTER the first stock was set
    if ($action === 'variance') {
        $stmt = $pdo->query('
            SELECT
                p.id,
                p.sku,
                p.name,
                COALESCE(s.initial_quantity, 0)                                         AS initial_qty,
                COALESCE(s.quantity, 0)                                                 AS actual_qty,
                COALESCE((SELECT SUM(quantity) FROM stock_movements
                          WHERE product_id = p.id AND movement_type = "addition"
                            AND reference_type != "initial" 
                            AND id != (SELECT MIN(id) FROM stock_movements sm2 
                                       WHERE sm2.product_id = p.id 
                                       AND sm2.movement_type = "addition")), 0)         AS total_restocked,
                COALESCE((SELECT SUM(quantity) FROM stock_movements
                          WHERE product_id = p.id AND movement_type = "deduction"
                            AND reference_type = "receipt"), 0)                         AS sold_qty,
                COALESCE((SELECT SUM(quantity) FROM stock_movements
                          WHERE product_id = p.id AND movement_type = "deduction"
                            AND (reference_type = "manual" OR reference_type IS NULL)), 0) AS manual_deductions,
                COALESCE((SELECT SUM(ABS(quantity)) FROM stock_movements
                          WHERE product_id = p.id AND movement_type = "adjustment"), 0) AS adjustments
            FROM products p
            LEFT JOIN stock s ON s.product_id = p.id
            WHERE p.id != 0 AND p.sku != "DELETED"
            ORDER BY p.name ASC
        ');

        $rows = $stmt->fetchAll();
        $result = [];

        foreach ($rows as $r) {
            $expected = (int)$r['initial_qty']
                      + (int)$r['total_restocked']
                      - (int)$r['sold_qty']
                      - (int)$r['manual_deductions'];
            $actual   = (int)$r['actual_qty'];
            $variance = $expected - $actual; // positive = missing

            $result[] = [
                'id'                => $r['id'],
                'sku'               => $r['sku'],
                'name'              => $r['name'],
                'initial_qty'       => (int)$r['initial_qty'],
                'total_restocked'   => (int)$r['total_restocked'],
                'sold_qty'          => (int)$r['sold_qty'],
                'manual_deductions' => (int)$r['manual_deductions'],
                'adjustments'       => (int)$r['adjustments'],
                'expected_qty'      => $expected,
                'actual_qty'        => $actual,
                'variance'          => $variance,
                'status'            => $variance > 0 ? 'missing'
                                     : ($variance < 0 ? 'surplus' : 'ok'),
            ];
        }

        // Summary
        $missing  = array_filter($result, fn($r) => $r['variance'] > 0);
        $surplus  = array_filter($result, fn($r) => $r['variance'] < 0);
        $ok       = array_filter($result, fn($r) => $r['variance'] === 0);

        echo json_encode([
            'items'   => $result,
            'summary' => [
                'total_products'  => count($result),
                'missing_count'   => count($missing),
                'surplus_count'   => count($surplus),
                'ok_count'        => count($ok),
                'total_missing'   => array_sum(array_column(array_values($missing), 'variance')),
            ]
        ]);
        exit;
    }

    // ── 2. SUSPICIOUS ACTIVITY ───────────────────────────────────────
    // Manual deductions, large adjustments, deleted receipts
    if ($action === 'suspicious') {
        // Manual stock deductions (not from receipts)
        $stmt = $pdo->prepare('
            SELECT
                sm.*,
                p.name  AS product_name,
                p.sku,
                u.username AS done_by
            FROM stock_movements sm
            JOIN products p ON sm.product_id = p.id
            LEFT JOIN users u ON sm.created_by = u.id
            WHERE sm.created_at >= ?
              AND (
                (sm.movement_type = "deduction" AND (sm.reference_type = "manual" OR sm.reference_type IS NULL))
                OR
                (sm.movement_type = "adjustment" AND ABS(sm.quantity) >= 5)
              )
            ORDER BY sm.created_at DESC
        ');
        $stmt->execute([$since . ' 00:00:00']);
        $suspicious = $stmt->fetchAll();

        // Large single-receipt deductions (qty >= 20 in one go)
        $stmtLarge = $pdo->prepare('
            SELECT
                sm.*,
                p.name  AS product_name,
                p.sku,
                u.username AS done_by
            FROM stock_movements sm
            JOIN products p ON sm.product_id = p.id
            LEFT JOIN users u ON sm.created_by = u.id
            WHERE sm.created_at >= ?
              AND sm.movement_type = "deduction"
              AND sm.reference_type = "receipt"
              AND sm.quantity >= 20
            ORDER BY sm.created_at DESC
        ');
        $stmtLarge->execute([$since . ' 00:00:00']);
        $largeDeductions = $stmtLarge->fetchAll();

        echo json_encode([
            'manual_deductions' => $suspicious,
            'large_deductions'  => $largeDeductions,
            'period_days'       => $days,
        ]);
        exit;
    }

    // ── 3. DAILY LOSS SUMMARY ────────────────────────────────────────
    if ($action === 'daily_loss') {
        $stmt = $pdo->prepare('
            SELECT
                DATE(sm.created_at)         AS date,
                COUNT(DISTINCT sm.product_id) AS products_affected,
                SUM(sm.quantity)            AS total_units_lost,
                GROUP_CONCAT(DISTINCT p.name) AS products
            FROM stock_movements sm
            JOIN products p ON sm.product_id = p.id
            WHERE sm.created_at >= ?
              AND sm.movement_type = "deduction"
              AND (sm.reference_type = "manual" OR sm.reference_type IS NULL)
            GROUP BY DATE(sm.created_at)
            ORDER BY date DESC
        ');
        $stmt->execute([$since . ' 00:00:00']);
        echo json_encode(['items' => $stmt->fetchAll(), 'period_days' => $days]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
