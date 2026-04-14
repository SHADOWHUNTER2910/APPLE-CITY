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

// Ensure tables exist
$pdo->exec('CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT DEFAULT NULL,
    address TEXT DEFAULT NULL,
    credit_limit REAL DEFAULT 0.0,
    notes TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS credit_sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    receipt_id INTEGER DEFAULT NULL,
    invoice_number TEXT DEFAULT NULL,
    amount_owed REAL NOT NULL DEFAULT 0.0,
    amount_paid REAL NOT NULL DEFAULT 0.0,
    balance REAL NOT NULL DEFAULT 0.0,
    status TEXT NOT NULL DEFAULT "unpaid",
    sale_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INTEGER DEFAULT NULL,
    FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY(receipt_id) REFERENCES receipts(id) ON DELETE SET NULL
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS credit_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    credit_sale_id INTEGER NOT NULL,
    customer_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    payment_method TEXT DEFAULT "cash",
    payment_reference TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    paid_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by INTEGER DEFAULT NULL,
    FOREIGN KEY(credit_sale_id) REFERENCES credit_sales(id) ON DELETE CASCADE,
    FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE
)');

try {
    $action = $_GET['action'] ?? '';

    // ── GET ──────────────────────────────────────────────────────────
    if ($method === 'GET') {

        // Single customer
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([$id]);
            $customer = $stmt->fetch();
            if (!$customer) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

            // Get their credit sales
            $cs = $pdo->prepare('SELECT * FROM credit_sales WHERE customer_id = ? ORDER BY sale_date DESC');
            $cs->execute([$id]);
            $customer['credit_sales'] = $cs->fetchAll();

            // Get total debt
            $debt = $pdo->prepare('SELECT COALESCE(SUM(balance),0) FROM credit_sales WHERE customer_id = ? AND status != "paid"');
            $debt->execute([$id]);
            $customer['total_debt'] = (float)$debt->fetchColumn();

            echo json_encode($customer);
            exit;
        }

        // Customers who owe (debt summary)
        if ($action === 'debtors') {
            $stmt = $pdo->query('
                SELECT c.id, c.name, c.phone, c.address,
                       COUNT(cs.id) as total_credit_sales,
                       COALESCE(SUM(cs.amount_owed),0) as total_owed,
                       COALESCE(SUM(cs.amount_paid),0) as total_paid,
                       COALESCE(SUM(cs.balance),0) as total_balance,
                       MAX(cs.sale_date) as last_sale_date
                FROM customers c
                JOIN credit_sales cs ON c.id = cs.customer_id
                WHERE cs.status != "paid"
                GROUP BY c.id
                ORDER BY total_balance DESC
            ');
            echo json_encode(['items' => $stmt->fetchAll()]);
            exit;
        }

        // Credit sales for a customer
        if ($action === 'credit_sales' && isset($_GET['customer_id'])) {
            $cid = (int)$_GET['customer_id'];
            $stmt = $pdo->prepare('
                SELECT cs.*, 
                       COALESCE(cs.invoice_number, "N/A") as invoice_number
                FROM credit_sales cs
                WHERE cs.customer_id = ?
                ORDER BY cs.sale_date DESC
            ');
            $stmt->execute([$cid]);
            echo json_encode(['items' => $stmt->fetchAll()]);
            exit;
        }

        // Payment history for a credit sale
        if ($action === 'payments' && isset($_GET['credit_sale_id'])) {
            $csid = (int)$_GET['credit_sale_id'];
            $stmt = $pdo->prepare('SELECT * FROM credit_payments WHERE credit_sale_id = ? ORDER BY paid_at DESC');
            $stmt->execute([$csid]);
            echo json_encode(['items' => $stmt->fetchAll()]);
            exit;
        }

        // All customers list
        $stmt = $pdo->query('
            SELECT c.*,
                   COALESCE(SUM(CASE WHEN cs.status != "paid" THEN cs.balance ELSE 0 END), 0) as total_debt
            FROM customers c
            LEFT JOIN credit_sales cs ON c.id = cs.customer_id
            GROUP BY c.id
            ORDER BY c.name ASC
        ');
        echo json_encode(['items' => $stmt->fetchAll()]);
        exit;
    }

    // ── POST ─────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $data = json_input();

        // Record a payment against a credit sale
        if ($action === 'pay') {
            $csid = (int)($data['credit_sale_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $paymentMethod = trim((string)($data['payment_method'] ?? 'cash'));
            $reference = trim((string)($data['payment_reference'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));

            if ($csid <= 0 || $amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'credit_sale_id and amount are required']);
                exit;
            }

            // Get credit sale
            $cs = $pdo->prepare('SELECT * FROM credit_sales WHERE id = ?');
            $cs->execute([$csid]);
            $sale = $cs->fetch();
            if (!$sale) { http_response_code(404); echo json_encode(['error' => 'Credit sale not found']); exit; }

            $newPaid = (float)$sale['amount_paid'] + $amount;
            $newBalance = max(0, (float)$sale['amount_owed'] - $newPaid);
            $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

            $pdo->beginTransaction();

            // Record payment
            $ins = $pdo->prepare('INSERT INTO credit_payments (credit_sale_id, customer_id, amount, payment_method, payment_reference, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$csid, $sale['customer_id'], $amount, $paymentMethod, $reference, $notes, $_SESSION['user_id']]);

            // Update credit sale
            $upd = $pdo->prepare('UPDATE credit_sales SET amount_paid = ?, balance = ?, status = ? WHERE id = ?');
            $upd->execute([$newPaid, $newBalance, $newStatus, $csid]);

            $pdo->commit();
            echo json_encode(['success' => true, 'new_balance' => $newBalance, 'status' => $newStatus]);
            exit;
        }

        // Add new customer
        $name = trim((string)($data['name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $creditLimit = (float)($data['credit_limit'] ?? 0);
        $notes = trim((string)($data['notes'] ?? ''));

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Customer name is required']);
            exit;
        }

        $ins = $pdo->prepare('INSERT INTO customers (name, phone, address, credit_limit, notes) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$name, $phone, $address, $creditLimit, $notes]);
        echo json_encode(['id' => (int)$pdo->lastInsertId(), 'success' => true]);
        exit;
    }

    // ── PUT ──────────────────────────────────────────────────────────
    if ($method === 'PUT') {
        $data = json_input();

        // Record a new credit sale manually
        if ($action === 'credit_sale') {
            $customerId = (int)($data['customer_id'] ?? 0);
            $amountOwed = (float)($data['amount_owed'] ?? 0);
            $invoiceNumber = trim((string)($data['invoice_number'] ?? ''));
            $receiptId = isset($data['receipt_id']) ? (int)$data['receipt_id'] : null;
            $dueDate = trim((string)($data['due_date'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));

            if ($customerId <= 0 || $amountOwed <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'customer_id and amount_owed are required']);
                exit;
            }

            $ins = $pdo->prepare('INSERT INTO credit_sales (customer_id, receipt_id, invoice_number, amount_owed, amount_paid, balance, status, due_date, notes, created_by) VALUES (?, ?, ?, ?, 0, ?, "unpaid", ?, ?, ?)');
            $ins->execute([$customerId, $receiptId, $invoiceNumber, $amountOwed, $amountOwed, $dueDate ?: null, $notes, $_SESSION['user_id']]);
            echo json_encode(['id' => (int)$pdo->lastInsertId(), 'success' => true]);
            exit;
        }

        // Update customer
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $name = trim((string)($data['name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $creditLimit = (float)($data['credit_limit'] ?? 0);
        $notes = trim((string)($data['notes'] ?? ''));

        $upd = $pdo->prepare('UPDATE customers SET name=?, phone=?, address=?, credit_limit=?, notes=? WHERE id=?');
        $upd->execute([$name, $phone, $address, $creditLimit, $notes, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────────────
    if ($method === 'DELETE') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin only']);
            exit;
        }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
