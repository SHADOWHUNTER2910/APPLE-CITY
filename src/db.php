<?php
declare(strict_types=1);

// Simple PDO connection helper. Usage: $pdo = get_pdo();
$cfgPath = __DIR__ . '/../config.php';
if (!file_exists($cfgPath)) {
    http_response_code(500);
    echo 'Missing config.php. Please create config.php with $DB_CONFIG.';
    exit;
}
require $cfgPath; // defines $DB_CONFIG

function ensure_sqlite_initialized(PDO $pdo, string $dbFile): void {
    $pdo->exec('PRAGMA foreign_keys = ON');

    // ── Products (iPhone models) ──────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        unit_price REAL NOT NULL DEFAULT 0.0,
        cost_price REAL NOT NULL DEFAULT 0.0,
        default_unit_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Migrate: add cost_price to existing products tables that don't have it
    try { $pdo->exec('ALTER TABLE products ADD COLUMN cost_price REAL NOT NULL DEFAULT 0.0'); } catch (Exception $e) {}
    // Migrate: add default_unit_id if missing
    try { $pdo->exec('ALTER TABLE products ADD COLUMN default_unit_id INTEGER DEFAULT NULL'); } catch (Exception $e) {}

    // ── Product Variants (model + storage + color combinations) ───────
    $pdo->exec('CREATE TABLE IF NOT EXISTS product_variants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        storage TEXT NOT NULL DEFAULT "",
        color TEXT NOT NULL DEFAULT "",
        selling_price REAL NOT NULL DEFAULT 0.0,
        cost_price REAL NOT NULL DEFAULT 0.0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE,
        UNIQUE(product_id, storage, color)
    )');

    // ── IMEI Units (one row per physical device) ───────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS imei_units (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        variant_id INTEGER DEFAULT NULL,
        imei TEXT NOT NULL UNIQUE,
        color TEXT DEFAULT NULL,
        storage TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT "in_stock",
        cost_price REAL NOT NULL DEFAULT 0.0,
        selling_price REAL NOT NULL DEFAULT 0.0,
        supplier_id INTEGER DEFAULT NULL,
        purchase_date TEXT DEFAULT NULL,
        sold_receipt_id INTEGER DEFAULT NULL,
        sold_at TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE RESTRICT,
        FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // ── Stock (aggregate counts per product, kept for dashboard speed) ─
    $pdo->exec('CREATE TABLE IF NOT EXISTS stock (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        quantity INTEGER NOT NULL DEFAULT 0,
        initial_quantity INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE RESTRICT
    )');

    // Migrate: add initial_quantity if missing
    try { $pdo->exec('ALTER TABLE stock ADD COLUMN initial_quantity INTEGER NOT NULL DEFAULT 0'); } catch (Exception $e) {}
    // Migrate: remove old expiry columns from stock if they exist (harmless if not)
    // SQLite doesn't support DROP COLUMN in older versions, so we just leave them

    // ── Suppliers ─────────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS suppliers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone TEXT DEFAULT NULL,
        email TEXT DEFAULT NULL,
        address TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Add supplier_id to imei_units if missing
    try { $pdo->exec('ALTER TABLE imei_units ADD COLUMN supplier_id INTEGER DEFAULT NULL'); } catch (Exception $e) {}

    // ── Receipts ──────────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL UNIQUE,
        customer_name TEXT,
        customer_phone TEXT,
        customer_address TEXT,
        dealer_name TEXT,
        company_name TEXT,
        company_location TEXT,
        subtotal REAL NOT NULL DEFAULT 0.0,
        discount REAL NOT NULL DEFAULT 0.0,
        total REAL NOT NULL DEFAULT 0.0,
        total_cost REAL NOT NULL DEFAULT 0.0,
        total_profit REAL NOT NULL DEFAULT 0.0,
        profit_margin REAL NOT NULL DEFAULT 0.0,
        payment_method TEXT NOT NULL DEFAULT "cash",
        payment_reference TEXT DEFAULT NULL,
        cash_received REAL DEFAULT 0.0,
        change_given REAL DEFAULT 0.0,
        created_by INTEGER DEFAULT NULL,
        created_by_username TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // Migrate receipts columns for existing databases
    $receiptCols = ['customer_phone TEXT','customer_address TEXT','cash_received REAL DEFAULT 0.0',
                    'change_given REAL DEFAULT 0.0','total_cost REAL DEFAULT 0.0',
                    'total_profit REAL DEFAULT 0.0','profit_margin REAL DEFAULT 0.0',
                    'discount REAL DEFAULT 0.0','payment_method TEXT DEFAULT "cash"',
                    'payment_reference TEXT DEFAULT NULL','created_by INTEGER DEFAULT NULL',
                    'created_by_username TEXT DEFAULT NULL',
                    'trade_in_id INTEGER DEFAULT NULL',
                    'trade_in_value REAL DEFAULT 0.0',
                    'trade_in_device TEXT DEFAULT NULL'];
    foreach ($receiptCols as $col) {
        try { $pdo->exec('ALTER TABLE receipts ADD COLUMN '.$col); } catch (Exception $e) {}
    }

    // ── Receipt Items ─────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS receipt_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_id INTEGER NOT NULL,
        product_id INTEGER,
        product_name TEXT,
        imei_unit_id INTEGER DEFAULT NULL,
        imei TEXT DEFAULT NULL,
        variant_label TEXT DEFAULT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL,
        cost_price REAL NOT NULL DEFAULT 0.0,
        total_price REAL NOT NULL,
        profit REAL NOT NULL DEFAULT 0.0,
        FOREIGN KEY(receipt_id) REFERENCES receipts(id) ON UPDATE CASCADE ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // Migrate receipt_items columns for existing databases
    $riCols = ['product_name TEXT','imei_unit_id INTEGER DEFAULT NULL','imei TEXT DEFAULT NULL',
               'variant_label TEXT DEFAULT NULL','cost_price REAL DEFAULT 0.0',
               'profit REAL DEFAULT 0.0'];
    foreach ($riCols as $col) {
        try { $pdo->exec('ALTER TABLE receipt_items ADD COLUMN '.$col); } catch (Exception $e) {}
    }

    // ── Users ─────────────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "user",
        status TEXT NOT NULL DEFAULT "active",
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // ── Stock Movements (audit trail) ─────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        quantity INTEGER NOT NULL,
        quantity_before INTEGER NOT NULL,
        quantity_after INTEGER NOT NULL,
        reference_type TEXT DEFAULT NULL,
        reference_id INTEGER DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // ── Repairs / Service Jobs ────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS repairs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_number TEXT NOT NULL UNIQUE,
        customer_name TEXT NOT NULL,
        customer_phone TEXT DEFAULT NULL,
        device_model TEXT NOT NULL,
        imei TEXT DEFAULT NULL,
        issue_description TEXT NOT NULL,
        diagnosis TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT "received",
        parts_cost REAL NOT NULL DEFAULT 0.0,
        labor_cost REAL NOT NULL DEFAULT 0.0,
        total_charge REAL NOT NULL DEFAULT 0.0,
        payment_status TEXT NOT NULL DEFAULT "unpaid",
        payment_method TEXT DEFAULT NULL,
        received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TEXT DEFAULT NULL,
        collected_at TEXT DEFAULT NULL,
        technician TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // ── Repair Parts Used ─────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS repair_parts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        repair_id INTEGER NOT NULL,
        part_name TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        unit_cost REAL NOT NULL DEFAULT 0.0,
        total_cost REAL NOT NULL DEFAULT 0.0,
        FOREIGN KEY(repair_id) REFERENCES repairs(id) ON UPDATE CASCADE ON DELETE CASCADE
    )');

    // ── Trade-ins ─────────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS trade_ins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_name TEXT NOT NULL,
        customer_phone TEXT DEFAULT NULL,
        device_model TEXT NOT NULL,
        imei TEXT DEFAULT NULL,
        condition TEXT NOT NULL DEFAULT "good",
        offered_value REAL NOT NULL DEFAULT 0.0,
        agreed_value REAL NOT NULL DEFAULT 0.0,
        linked_receipt_id INTEGER DEFAULT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        added_to_inventory INTEGER NOT NULL DEFAULT 0,
        inventory_imei_id INTEGER DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(linked_receipt_id) REFERENCES receipts(id) ON UPDATE CASCADE ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');
    try { $pdo->exec('ALTER TABLE trade_ins ADD COLUMN added_to_inventory INTEGER NOT NULL DEFAULT 0'); } catch (Exception $e) {}
    try { $pdo->exec('ALTER TABLE trade_ins ADD COLUMN inventory_imei_id INTEGER DEFAULT NULL'); } catch (Exception $e) {}

    // ── Warranties ────────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS warranties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_id INTEGER NOT NULL,
        receipt_item_id INTEGER DEFAULT NULL,
        imei TEXT NOT NULL,
        product_name TEXT NOT NULL,
        customer_name TEXT DEFAULT NULL,
        customer_phone TEXT DEFAULT NULL,
        warranty_months INTEGER NOT NULL DEFAULT 12,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "active",
        notes TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(receipt_id) REFERENCES receipts(id) ON UPDATE CASCADE ON DELETE CASCADE
    )');

    // ── Customers ─────────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone TEXT DEFAULT NULL,
        email TEXT DEFAULT NULL,
        address TEXT DEFAULT NULL,
        credit_limit REAL NOT NULL DEFAULT 0.0,
        total_purchases REAL NOT NULL DEFAULT 0.0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // ── Credit Sales ──────────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS credit_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        receipt_id INTEGER DEFAULT NULL,
        amount_owed REAL NOT NULL DEFAULT 0.0,
        amount_paid REAL NOT NULL DEFAULT 0.0,
        balance REAL NOT NULL DEFAULT 0.0,
        status TEXT NOT NULL DEFAULT "outstanding",
        due_date TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE RESTRICT,
        FOREIGN KEY(receipt_id) REFERENCES receipts(id) ON UPDATE CASCADE ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // ── Credit Payments ───────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS credit_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        credit_sale_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        payment_method TEXT NOT NULL DEFAULT "cash",
        payment_reference TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(credit_sale_id) REFERENCES credit_sales(id) ON UPDATE CASCADE ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // ── Company Settings ──────────────────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS company_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Default company settings for Apple City
    $defaults = [
        'company_name'     => 'Apple City',
        'company_subtitle' => 'Authorised iPhone Dealer',
        'company_location' => 'Location: Your Address',
        'company_phone'    => 'TEL: Your Phone Number',
        'company_email'    => '',
        'company_website'  => '',
        'company_logo'     => 'assets/logo.png',
        'warranty_months'  => '12',
        'currency_symbol'  => 'GH₵',
    ];
    foreach ($defaults as $key => $value) {
        try {
            $pdo->prepare('INSERT OR IGNORE INTO company_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
        } catch (Exception $e) {}
    }

    // ── Indexes ───────────────────────────────────────────────────────
    $indexes = [
        'CREATE INDEX IF NOT EXISTS idx_products_name ON products(name)',
        'CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku)',
        'CREATE INDEX IF NOT EXISTS idx_stock_product_id ON stock(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_receipts_created_at ON receipts(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_receipts_invoice ON receipts(invoice_number)',
        'CREATE INDEX IF NOT EXISTS idx_receipt_items_receipt ON receipt_items(receipt_id)',
        'CREATE INDEX IF NOT EXISTS idx_receipt_items_product ON receipt_items(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_imei_units_imei ON imei_units(imei)',
        'CREATE INDEX IF NOT EXISTS idx_imei_units_product ON imei_units(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_imei_units_status ON imei_units(status)',
        'CREATE INDEX IF NOT EXISTS idx_repairs_status ON repairs(status)',
        'CREATE INDEX IF NOT EXISTS idx_repairs_job_number ON repairs(job_number)',
        'CREATE INDEX IF NOT EXISTS idx_warranties_imei ON warranties(imei)',
        'CREATE INDEX IF NOT EXISTS idx_warranties_end_date ON warranties(end_date)',
        'CREATE INDEX IF NOT EXISTS idx_stock_movements_product ON stock_movements(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_movements_date ON stock_movements(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_product_variants_product ON product_variants(product_id)',
    ];
    foreach ($indexes as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }
}

function seed_admin_if_missing(PDO $pdo): void {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
            ->execute(['admin', password_hash('Admin@123', PASSWORD_DEFAULT), 'admin']);
    }
}

function ensure_deleted_product_placeholder(PDO $pdo): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = 0");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        try {
            $pdo->exec("INSERT INTO products (id, sku, name, unit_price) VALUES (0, 'DELETED', '[DELETED PRODUCT]', 0.00)");
            $pdo->exec("INSERT INTO stock (product_id, quantity) VALUES (0, 0)");
        } catch (Exception $e) {}
    }
}

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    global $DB_CONFIG;
    $driver = $DB_CONFIG['driver'] ?? 'sqlite';

    if ($driver === 'sqlite') {
        $dbFile = $DB_CONFIG['sqlite_path'] ?? (__DIR__ . '/../data/stocktracker.sqlite');
        $dir = dirname($dbFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO('sqlite:' . $dbFile, null, null, $options);
            ensure_sqlite_initialized($pdo, $dbFile);
            seed_admin_if_missing($pdo);
            ensure_deleted_product_placeholder($pdo);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'SQLite connection failed: ' . $e->getMessage();
            exit;
        }
        return $pdo;
    }

    // MySQL fallback
    $dsn = "mysql:host={$DB_CONFIG['host']};dbname={$DB_CONFIG['name']};charset={$DB_CONFIG['charset']}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['pass'], $options);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Database connection failed.';
        exit;
    }
    return $pdo;
}

function getDbConnection(): PDO { return get_pdo(); }
