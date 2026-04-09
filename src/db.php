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
    // Create tables if they don't exist (SQLite DDL)
    // Note: SQLite supports FOREIGN KEYs but they need PRAGMA foreign_keys = ON
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Products
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        unit_price REAL NOT NULL DEFAULT 0.0,
        has_expiry INTEGER DEFAULT 0,
        shelf_life_days INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Stock
    $pdo->exec('CREATE TABLE IF NOT EXISTS stock (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        quantity INTEGER NOT NULL DEFAULT 0,
        initial_quantity INTEGER NOT NULL DEFAULT 0,
        manufacturing_date TEXT DEFAULT NULL,
        expiry_date TEXT DEFAULT NULL,
        batch_number TEXT DEFAULT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE RESTRICT
    )');
    
    // Add initial_quantity column if it doesn't exist
    try {
        $pdo->exec('ALTER TABLE stock ADD COLUMN initial_quantity INTEGER DEFAULT 0');
    } catch (Exception $e) {
        // Column already exists, ignore
    }

    // Receipts - Create with all columns
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
        total REAL NOT NULL DEFAULT 0.0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Add missing columns to existing receipts table if they don't exist
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN customer_phone TEXT');
    } catch (Exception $e) {
        // Column already exists, ignore
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN customer_address TEXT');
    } catch (Exception $e) {
        // Column already exists, ignore
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN cash_received REAL DEFAULT 0.0');
    } catch (Exception $e) {
        // Column already exists, ignore
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN change_given REAL DEFAULT 0.0');
    } catch (Exception $e) {
        // Column already exists, ignore
    }

    // Receipt items - Create with all columns
    $pdo->exec('CREATE TABLE IF NOT EXISTS receipt_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_id INTEGER NOT NULL,
        product_id INTEGER,
        product_name TEXT,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        total_price REAL NOT NULL,
        batch_id INTEGER DEFAULT NULL,
        expiry_date TEXT DEFAULT NULL,
        FOREIGN KEY(receipt_id) REFERENCES receipts(id) ON UPDATE CASCADE ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE SET NULL
    )');

    // Add missing columns to existing receipt_items table if they don't exist
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN product_name TEXT');
    } catch (Exception $e) {
        // Column already exists, ignore
    }

    // Users
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'user\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Stock batches for expiry tracking
    $pdo->exec('CREATE TABLE IF NOT EXISTS stock_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        batch_number TEXT NOT NULL,
        manufacturing_date TEXT DEFAULT NULL,
        expiry_date TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE
    )');
    
    // Create indexes for better performance
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_batches_expiry ON stock_batches(expiry_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_batches_product ON stock_batches(product_id)');
        
        // Add critical indexes for production performance
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_name ON products(name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_has_expiry ON products(has_expiry)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_product_id ON stock(product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_quantity ON stock(quantity)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_created_at ON receipts(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_invoice ON receipts(invoice_number)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipt_items_receipt ON receipt_items(receipt_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipt_items_product ON receipt_items(product_id)');
    } catch (Exception $e) {
        // Indexes might already exist
    }
    
    // Product units for multi-unit support
    $pdo->exec('CREATE TABLE IF NOT EXISTS product_units (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        unit_name TEXT NOT NULL,
        unit_abbreviation TEXT NOT NULL,
        conversion_factor REAL NOT NULL DEFAULT 1.0,
        unit_price REAL NOT NULL DEFAULT 0.0,
        is_base_unit INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE,
        UNIQUE(product_id, unit_name)
    )');
    
    // Create indexes for product_units
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_units_product ON product_units(product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_units_base ON product_units(product_id, is_base_unit)');
    } catch (Exception $e) {
        // Indexes might already exist
    }
    
    // Add columns to receipt_items for unit tracking
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN unit_id INTEGER DEFAULT NULL');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN unit_name TEXT DEFAULT NULL');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN unit_abbreviation TEXT DEFAULT NULL');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN quantity_in_base_unit REAL DEFAULT NULL');
    } catch (Exception $e) {
        // Column already exists
    }
    
    // Add default_unit_id to products
    try {
        $pdo->exec('ALTER TABLE products ADD COLUMN default_unit_id INTEGER DEFAULT NULL');
    } catch (Exception $e) {
        // Column already exists
    }
    
    // Add profit tracking columns to product_units (cost varies per unit)
    try {
        $pdo->exec('ALTER TABLE product_units ADD COLUMN cost_price REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN cost_price REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipt_items ADD COLUMN profit REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN total_cost REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN total_profit REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN profit_margin REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    // Discount column
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN discount REAL DEFAULT 0.00');
    } catch (Exception $e) {
        // Column already exists
    }
    // Payment method columns
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN payment_method TEXT DEFAULT "cash"');
    } catch (Exception $e) {
        // Column already exists
    }
    try {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN payment_reference TEXT DEFAULT NULL');
    } catch (Exception $e) {
        // Column already exists
    }
    
    // Create indexes for profit tracking
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_units_cost_price ON product_units(cost_price)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipt_items_profit ON receipt_items(profit)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_profit ON receipts(total_profit)');
    } catch (Exception $e) {
        // Indexes might already exist
    }
    
    // Stock movements table for tracking additions and deductions
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
    
    // Create indexes for stock_movements
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_movements_product ON stock_movements(product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_movements_type ON stock_movements(movement_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_movements_date ON stock_movements(created_at)');
    } catch (Exception $e) {
        // Indexes might already exist
    }
    
    // Company settings table
    $pdo->exec('CREATE TABLE IF NOT EXISTS company_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Insert default company settings if they don't exist
    $defaultSettings = [
        'company_name' => 'Your Company Name',
        'company_subtitle' => 'Business Description',
        'company_location' => 'Location: Your Business Address',
        'company_phone' => 'TEL: Your Phone Number',
        'company_email' => '',
        'company_website' => '',
        'company_logo' => 'assets/logo.png'
    ];
    
    foreach ($defaultSettings as $key => $value) {
        try {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO company_settings (setting_key, setting_value) VALUES (?, ?)');
            $stmt->execute([$key, $value]);
        } catch (Exception $e) {
            // Setting already exists
        }
    }
}

function seed_admin_if_missing(PDO $pdo): void {
    // Create a default admin if none exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) {
        $username = 'admin';
        $passwordHash = password_hash('Admin@123', PASSWORD_DEFAULT);
        $role = 'admin';
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $ins->execute([$username, $passwordHash, $role]);
    }
}

function ensure_deleted_product_placeholder(PDO $pdo): void {
    // Create a special "deleted product" placeholder with ID 0 if it doesn't exist
    // This placeholder is used to maintain referential integrity when products are force-deleted
    // It should never appear in normal product listings (filtered out by APIs)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = 0");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    
    if ($count === 0) {
        try {
            // Insert with explicit ID 0 for deleted products placeholder
            $pdo->exec("INSERT INTO products (id, sku, name, unit_price) VALUES (0, 'DELETED', '[DELETED PRODUCT]', 0.00)");
            // Also create stock entry for the deleted product placeholder
            $pdo->exec("INSERT INTO stock (product_id, quantity) VALUES (0, 0)");
        } catch (Exception $e) {
            // If ID 0 insertion fails, use a high negative number
            try {
                $pdo->exec("INSERT INTO products (sku, name, unit_price) VALUES ('DELETED-PLACEHOLDER', '[DELETED PRODUCT]', 0.00)");
                $lastId = $pdo->lastInsertId();
                $pdo->exec("INSERT INTO stock (product_id, quantity) VALUES ($lastId, 0)");
            } catch (Exception $e2) {
                // Placeholder creation failed, continue without it
                error_log("Could not create deleted product placeholder: " . $e2->getMessage());
            }
        }
    }
}

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_CONFIG;
    $driver = $DB_CONFIG['driver'] ?? 'mysql';

    if ($driver === 'sqlite') {
        $dbFile = $DB_CONFIG['sqlite_path'] ?? (__DIR__ . '/../data/stocktracker.sqlite');
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $dsn = 'sqlite:' . $dbFile;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, null, null, $options);
            ensure_sqlite_initialized($pdo, $dbFile);
            seed_admin_if_missing($pdo);
            ensure_deleted_product_placeholder($pdo);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'SQLite connection failed.';
            exit;
        }
        return $pdo;
    }

    // Fallback to MySQL (legacy mode)
    $host = $DB_CONFIG['host'] ?? '127.0.0.1';
    $db   = $DB_CONFIG['name'] ?? 'stocktracker';
    $user = $DB_CONFIG['user'] ?? 'root';
    $pass = $DB_CONFIG['pass'] ?? '';
    $charset = $DB_CONFIG['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Database connection failed.';
        exit;
    }

    return $pdo;
}


// Alias for consistency with other API files
function getDbConnection(): PDO {
    return get_pdo();
}
