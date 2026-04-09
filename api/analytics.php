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