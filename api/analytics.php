<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

// Debug session info
error_log("Analytics API - Session user_id: " . ($_SESSION['user_id'] ?? 'not set'));
error_log("Analytics API - Session role: " . ($_SESSION['role'] ?? 'not set'));

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden', 'debug' => 'User not logged in or not admin']);
    exit;
}

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'dashboard';
        $date = $_GET['date'] ?? date('Y-m-d');
        
        switch ($type) {
            case 'dashboard':
                // Daily dashboard analytics
                $analytics = [];
                
                // Today's sales summary with profit
                $stmt = $pdo->prepare('
                    SELECT 
                        COUNT(*) as total_receipts,
                        COALESCE(SUM(total), 0) as total_income,
                        COALESCE(SUM(total_cost), 0) as total_cost,
                        COALESCE(SUM(total_profit), 0) as total_profit,
                        CASE 
                            WHEN SUM(total) > 0 THEN ROUND((SUM(total_profit) / SUM(total)) * 100, 2)
                            ELSE 0 
                        END as profit_margin,
                        COUNT(DISTINCT created_by) as active_users
                    FROM receipts 
                    WHERE DATE(created_at) = ?
                ');
                $stmt->execute([$date]);
                $analytics['daily_summary'] = $stmt->fetch();
                
                // Top selling products today with profit (discount-adjusted)
                $stmt = $pdo->prepare('
                    SELECT 
                        COALESCE(ri.product_name, p.name, "Unknown Product") as product_name,
                        COALESCE(p.sku, "N/A") as sku,
                        SUM(ri.quantity) as total_sold,
                        SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) as total_revenue,
                        SUM((ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) - (ri.cost_price * ri.quantity)) as total_profit,
                        CASE 
                            WHEN SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) > 0 
                            THEN ROUND((SUM((ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) - (ri.cost_price * ri.quantity)) / SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END)) * 100, 2)
                            ELSE 0 
                        END as profit_margin
                    FROM receipt_items ri
                    JOIN receipts r ON ri.receipt_id = r.id
                    LEFT JOIN products p ON ri.product_id = p.id
                    WHERE DATE(r.created_at) = ?
                    GROUP BY ri.product_id, ri.product_name
                    ORDER BY total_sold DESC
                    LIMIT 10
                ');
                $stmt->execute([$date]);
                $analytics['top_products'] = $stmt->fetchAll();
                
                // Most profitable products today (discount-adjusted)
                $stmt = $pdo->prepare('
                    SELECT 
                        COALESCE(ri.product_name, p.name, "Unknown Product") as product_name,
                        COALESCE(p.sku, "N/A") as sku,
                        SUM(ri.quantity) as total_sold,
                        SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) as total_revenue,
                        SUM((ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) - (ri.cost_price * ri.quantity)) as total_profit,
                        CASE 
                            WHEN SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) > 0 
                            THEN ROUND((SUM((ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) - (ri.cost_price * ri.quantity)) / SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END)) * 100, 2)
                            ELSE 0 
                        END as profit_margin
                    FROM receipt_items ri
                    JOIN receipts r ON ri.receipt_id = r.id
                    LEFT JOIN products p ON ri.product_id = p.id
                    WHERE DATE(r.created_at) = ?
                    GROUP BY ri.product_id, ri.product_name
                    ORDER BY total_profit DESC
                    LIMIT 10
                ');
                $stmt->execute([$date]);
                $analytics['most_profitable_products'] = $stmt->fetchAll();
                
                // Sales by user today with profit
                $stmt = $pdo->prepare('
                    SELECT 
                        COALESCE(created_by_username, "Unknown") as username,
                        COUNT(*) as receipts_count,
                        SUM(total) as total_sales,
                        SUM(total_profit) as total_profit,
                        CASE 
                            WHEN SUM(total) > 0 THEN ROUND((SUM(total_profit) / SUM(total)) * 100, 2)
                            ELSE 0 
                        END as profit_margin
                    FROM receipts 
                    WHERE DATE(created_at) = ?
                    GROUP BY created_by, created_by_username
                    ORDER BY total_sales DESC
                ');
                $stmt->execute([$date]);
                $analytics['sales_by_user'] = $stmt->fetchAll();
                
                // Hourly sales pattern with profit
                $stmt = $pdo->prepare('
                    SELECT 
                        strftime("%H", created_at) as hour,
                        COUNT(*) as receipts_count,
                        SUM(total) as total_sales,
                        SUM(total_profit) as total_profit
                    FROM receipts 
                    WHERE DATE(created_at) = ?
                    GROUP BY strftime("%H", created_at)
                    ORDER BY hour
                ');
                $stmt->execute([$date]);
                $analytics['hourly_pattern'] = $stmt->fetchAll();
                
                // Low profit products alert (units with low margin)
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) as count
                    FROM product_units pu
                    JOIN products p ON pu.product_id = p.id
                    WHERE p.id != 0 AND p.sku != "DELETED" 
                    AND pu.unit_price > 0 
                    AND ((pu.unit_price - pu.cost_price) / pu.unit_price) * 100 < 10
                ');
                $stmt->execute();
                $analytics['low_profit_products_count'] = (int)$stmt->fetchColumn();
                
                // Negative profit products alert (units selling below cost)
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) as count
                    FROM product_units pu
                    JOIN products p ON pu.product_id = p.id
                    WHERE p.id != 0 AND p.sku != "DELETED" 
                    AND pu.cost_price > pu.unit_price
                ');
                $stmt->execute();
                $analytics['negative_profit_products_count'] = (int)$stmt->fetchColumn();
                
                echo json_encode($analytics);
                break;
                
            case 'weekly':
                // Weekly analytics
                $startDate = date('Y-m-d', strtotime($date . ' -6 days'));
                $endDate = $date;
                
                $stmt = $pdo->prepare('
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as receipts_count,
                        SUM(total) as total_sales
                    FROM receipts 
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ');
                $stmt->execute([$startDate, $endDate]);
                echo json_encode(['weekly_sales' => $stmt->fetchAll()]);
                break;
                
            case 'monthly':
                // Monthly analytics
                $month = $_GET['month'] ?? date('Y-m');
                
                $stmt = $pdo->prepare('
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as receipts_count,
                        SUM(total) as total_sales
                    FROM receipts 
                    WHERE strftime("%Y-%m", created_at) = ?
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ');
                $stmt->execute([$month]);
                echo json_encode(['monthly_sales' => $stmt->fetchAll()]);
                break;
                
            case 'user_performance':
                // User performance analytics
                $period = $_GET['period'] ?? '30'; // days
                $startDate = date('Y-m-d', strtotime("-{$period} days"));
                
                $stmt = $pdo->prepare('
                    SELECT 
                        created_by_username as username,
                        COUNT(*) as total_receipts,
                        SUM(total) as total_sales,
                        AVG(total) as avg_sale_amount,
                        MIN(created_at) as first_sale,
                        MAX(created_at) as last_sale
                    FROM receipts 
                    WHERE DATE(created_at) >= ?
                    GROUP BY created_by, created_by_username
                    ORDER BY total_sales DESC
                ');
                $stmt->execute([$startDate]);
                echo json_encode(['user_performance' => $stmt->fetchAll()]);
                break;
                
            case 'product_performance':
                // Product performance analytics
                $period = $_GET['period'] ?? '30'; // days
                $startDate = date('Y-m-d', strtotime("-{$period} days"));
                
                $stmt = $pdo->prepare('
                    SELECT 
                        ri.product_name,
                        p.sku,
                        pu.unit_name,
                        pu.cost_price,
                        pu.unit_price,
                        (pu.unit_price - pu.cost_price) as profit_per_unit,
                        CASE 
                            WHEN pu.unit_price > 0 THEN ROUND(((pu.unit_price - pu.cost_price) / pu.unit_price) * 100, 2)
                            ELSE 0 
                        END as profit_margin_percent,
                        SUM(ri.quantity) as total_sold,
                        SUM(ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) as total_revenue,
                        SUM((ri.total_price * CASE WHEN r.subtotal > 0 THEN r.total / r.subtotal ELSE 1 END) - (ri.cost_price * ri.quantity)) as total_profit,
                        AVG(ri.unit_price) as avg_price,
                        COUNT(DISTINCT r.id) as times_sold
                    FROM receipt_items ri
                    JOIN receipts r ON ri.receipt_id = r.id
                    LEFT JOIN products p ON ri.product_id = p.id
                    LEFT JOIN product_units pu ON ri.unit_id = pu.id
                    WHERE DATE(r.created_at) >= ?
                    GROUP BY ri.product_id, ri.unit_id, ri.product_name
                    ORDER BY total_revenue DESC
                ');
                $stmt->execute([$startDate]);
                echo json_encode(['product_performance' => $stmt->fetchAll()]);
                break;
                
            case 'profit_summary':
                // Profit summary for a period
                $period = $_GET['period'] ?? '30'; // days
                $startDate = date('Y-m-d', strtotime("-{$period} days"));
                
                $stmt = $pdo->prepare('
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as receipts_count,
                        SUM(total) as total_revenue,
                        SUM(total_cost) as total_cost,
                        SUM(total_profit) as total_profit,
                        CASE 
                            WHEN SUM(total) > 0 THEN ROUND((SUM(total_profit) / SUM(total)) * 100, 2)
                            ELSE 0 
                        END as profit_margin
                    FROM receipts 
                    WHERE DATE(created_at) >= ?
                    GROUP BY DATE(created_at)
                    ORDER BY date DESC
                ');
                $stmt->execute([$startDate]);
                echo json_encode(['profit_summary' => $stmt->fetchAll()]);
                break;
                
            case 'low_profit_products':
                // Product units with low or negative profit margins
                $stmt = $pdo->query('
                    SELECT 
                        pu.id as unit_id,
                        p.id as product_id,
                        p.sku,
                        p.name as product_name,
                        pu.unit_name,
                        pu.unit_abbreviation,
                        pu.cost_price,
                        pu.unit_price,
                        (pu.unit_price - pu.cost_price) as profit_per_unit,
                        CASE 
                            WHEN pu.unit_price > 0 THEN ROUND(((pu.unit_price - pu.cost_price) / pu.unit_price) * 100, 2)
                            ELSE 0 
                        END as profit_margin_percent
                    FROM product_units pu
                    JOIN products p ON pu.product_id = p.id
                    WHERE p.id != 0 AND p.sku != "DELETED"
                    AND (
                        pu.cost_price > pu.unit_price 
                        OR (pu.unit_price > 0 AND ((pu.unit_price - pu.cost_price) / pu.unit_price) * 100 < 10)
                    )
                    ORDER BY profit_margin_percent ASC
                ');
                echo json_encode(['low_profit_products' => $stmt->fetchAll()]);
                break;

            case 'inventory_predictions':
                // Predict days until stockout based on 30-day sales velocity
                $period = 30;
                $startDate = date('Y-m-d', strtotime("-{$period} days"));

                $stmt = $pdo->prepare('
                    SELECT
                        p.id,
                        p.sku,
                        p.name,
                        COALESCE(s.quantity, 0) AS current_stock,
                        COALESCE(
                            (SELECT SUM(sm.quantity) FROM stock_movements sm
                             WHERE sm.product_id = p.id
                               AND sm.movement_type = "deduction"
                               AND sm.reference_type = "receipt"
                               AND sm.created_at >= ?),
                            0
                        ) AS sold_last_30,
                        COALESCE(
                            (SELECT MIN(pu.unit_price) FROM product_units pu WHERE pu.product_id = p.id),
                            p.unit_price
                        ) AS unit_price
                    FROM products p
                    LEFT JOIN stock s ON s.product_id = p.id
                    WHERE p.id != 0 AND p.sku != "DELETED"
                    ORDER BY p.name ASC
                ');
                $stmt->execute([$startDate . ' 00:00:00']);
                $rows = $stmt->fetchAll();

                $predictions = [];
                foreach ($rows as $r) {
                    $stock     = (int)$r['current_stock'];
                    $sold30    = (float)$r['sold_last_30'];
                    $dailyRate = $sold30 / $period;

                    if ($dailyRate > 0) {
                        $daysLeft = $stock / $dailyRate;
                    } else {
                        $daysLeft = null; // No sales data
                    }

                    $status = 'ok';
                    if ($stock === 0) $status = 'out_of_stock';
                    elseif ($daysLeft !== null && $daysLeft <= 3)  $status = 'critical';
                    elseif ($daysLeft !== null && $daysLeft <= 7)  $status = 'urgent';
                    elseif ($daysLeft !== null && $daysLeft <= 14) $status = 'low';
                    elseif ($daysLeft !== null && $daysLeft <= 30) $status = 'watch';

                    if ($status !== 'ok' || $stock === 0) {
                        $predictions[] = [
                            'id'           => $r['id'],
                            'sku'          => $r['sku'],
                            'name'         => $r['name'],
                            'current_stock'=> $stock,
                            'sold_last_30' => round($sold30, 1),
                            'daily_rate'   => round($dailyRate, 2),
                            'days_left'    => $daysLeft !== null ? round($daysLeft, 1) : null,
                            'reorder_qty'  => max(10, (int)ceil($dailyRate * 30)), // 30-day reorder
                            'status'       => $status,
                        ];
                    }
                }

                // Sort: out_of_stock first, then by days_left ascending
                usort($predictions, function($a, $b) {
                    $order = ['out_of_stock'=>0,'critical'=>1,'urgent'=>2,'low'=>3,'watch'=>4,'ok'=>5];
                    $ao = $order[$a['status']] ?? 5;
                    $bo = $order[$b['status']] ?? 5;
                    if ($ao !== $bo) return $ao - $bo;
                    $al = $a['days_left'] ?? 999;
                    $bl = $b['days_left'] ?? 999;
                    return $al <=> $bl;
                });

                echo json_encode([
                    'predictions'   => $predictions,
                    'period_days'   => $period,
                    'total_flagged' => count($predictions),
                ]);
                break;

            case 'advanced_analytics':
                // Profit trends (last 30 days)
                $period = (int)($_GET['period'] ?? 30);
                $startDate = date('Y-m-d', strtotime("-{$period} days"));

                // 30-day profit trend
                $stmt = $pdo->prepare('
                    SELECT DATE(created_at) as date,
                           COALESCE(SUM(total),0) as revenue,
                           COALESCE(SUM(total_cost),0) as cost,
                           COALESCE(SUM(total_profit),0) as profit,
                           COUNT(*) as receipts
                    FROM receipts WHERE DATE(created_at) >= ?
                    GROUP BY DATE(created_at) ORDER BY date ASC
                ');
                $stmt->execute([$startDate]);
                $profitTrend = $stmt->fetchAll();

                // Expiry loss estimation (expired batches that were deleted = lost stock)
                // We track this via stock movements with reference_type = 'expiry_deletion' or batch deletes
                $stmt = $pdo->prepare('
                    SELECT
                        COALESCE(SUM(sb_deleted.qty * pu.cost_price), 0) as estimated_expiry_loss
                    FROM (
                        SELECT sm.product_id, SUM(sm.quantity) as qty
                        FROM stock_movements sm
                        WHERE sm.movement_type = "deduction"
                          AND sm.notes LIKE "%expired%"
                          AND sm.created_at >= ?
                        GROUP BY sm.product_id
                    ) sb_deleted
                    LEFT JOIN product_units pu ON pu.product_id = sb_deleted.product_id AND pu.is_base_unit = 1
                ');
                $stmt->execute([$startDate . ' 00:00:00']);
                $expiryLoss = (float)$stmt->fetchColumn();

                // Theft estimation (manual deductions * avg cost)
                $stmt = $pdo->prepare('
                    SELECT COALESCE(SUM(sm.quantity * COALESCE(pu.cost_price, 0)), 0) as theft_estimate
                    FROM stock_movements sm
                    LEFT JOIN product_units pu ON pu.product_id = sm.product_id AND pu.is_base_unit = 1
                    WHERE sm.movement_type = "deduction"
                      AND (sm.reference_type = "manual" OR sm.reference_type IS NULL)
                      AND sm.created_at >= ?
                ');
                $stmt->execute([$startDate . ' 00:00:00']);
                $theftEstimate = (float)$stmt->fetchColumn();

                // Best products (top 10 by profit)
                $stmt = $pdo->prepare('
                    SELECT COALESCE(ri.product_name, p.name, "Unknown") as name,
                           SUM(ri.quantity) as units_sold,
                           SUM(ri.total_price) as revenue,
                           SUM(ri.profit) as profit,
                           CASE WHEN SUM(ri.total_price) > 0
                                THEN ROUND((SUM(ri.profit)/SUM(ri.total_price))*100,1)
                                ELSE 0 END as margin
                    FROM receipt_items ri
                    JOIN receipts r ON ri.receipt_id = r.id
                    LEFT JOIN products p ON ri.product_id = p.id
                    WHERE DATE(r.created_at) >= ?
                    GROUP BY ri.product_id, ri.product_name
                    ORDER BY profit DESC LIMIT 10
                ');
                $stmt->execute([$startDate]);
                $bestProducts = $stmt->fetchAll();

                // Worst products (lowest margin, min 5 sales)
                $stmt = $pdo->prepare('
                    SELECT COALESCE(ri.product_name, p.name, "Unknown") as name,
                           SUM(ri.quantity) as units_sold,
                           SUM(ri.total_price) as revenue,
                           SUM(ri.profit) as profit,
                           CASE WHEN SUM(ri.total_price) > 0
                                THEN ROUND((SUM(ri.profit)/SUM(ri.total_price))*100,1)
                                ELSE 0 END as margin
                    FROM receipt_items ri
                    JOIN receipts r ON ri.receipt_id = r.id
                    LEFT JOIN products p ON ri.product_id = p.id
                    WHERE DATE(r.created_at) >= ?
                    GROUP BY ri.product_id, ri.product_name
                    HAVING SUM(ri.quantity) >= 3
                    ORDER BY margin ASC LIMIT 10
                ');
                $stmt->execute([$startDate]);
                $worstProducts = $stmt->fetchAll();

                // Overall summary
                $stmt = $pdo->prepare('
                    SELECT COALESCE(SUM(total),0) as total_revenue,
                           COALESCE(SUM(total_cost),0) as total_cost,
                           COALESCE(SUM(total_profit),0) as total_profit,
                           COUNT(*) as total_receipts,
                           CASE WHEN SUM(total)>0 THEN ROUND((SUM(total_profit)/SUM(total))*100,2) ELSE 0 END as avg_margin
                    FROM receipts WHERE DATE(created_at) >= ?
                ');
                $stmt->execute([$startDate]);
                $summary = $stmt->fetch();

                echo json_encode([
                    'profit_trend'    => $profitTrend,
                    'expiry_loss'     => round($expiryLoss, 2),
                    'theft_estimate'  => round($theftEstimate, 2),
                    'best_products'   => $bestProducts,
                    'worst_products'  => $worstProducts,
                    'summary'         => $summary,
                    'period_days'     => $period,
                ]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid analytics type']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log("Analytics API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected server error', 'debug' => $e->getMessage()]);
}