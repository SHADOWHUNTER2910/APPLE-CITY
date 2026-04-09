-- Add expiry tracking to products and stock tables
-- Run this migration to add expiry date tracking functionality

-- Add expiry-related columns to products table
ALTER TABLE products ADD COLUMN has_expiry INTEGER DEFAULT 0; -- 0 = no expiry, 1 = has expiry
ALTER TABLE products ADD COLUMN shelf_life_days INTEGER DEFAULT NULL; -- Optional: default shelf life in days

-- Add expiry tracking to stock table
ALTER TABLE stock ADD COLUMN manufacturing_date TEXT DEFAULT NULL;
ALTER TABLE stock ADD COLUMN expiry_date TEXT DEFAULT NULL;
ALTER TABLE stock ADD COLUMN batch_number TEXT DEFAULT NULL;

-- Create a new table for batch tracking (for products with multiple batches)
CREATE TABLE IF NOT EXISTS stock_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    batch_number TEXT NOT NULL,
    manufacturing_date TEXT NOT NULL,
    expiry_date TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE
);

-- Create index for faster expiry queries
CREATE INDEX IF NOT EXISTS idx_stock_batches_expiry ON stock_batches(expiry_date);
CREATE INDEX IF NOT EXISTS idx_stock_batches_product ON stock_batches(product_id);

-- Add expiry tracking to receipt items (to track which batch was sold)
ALTER TABLE receipt_items ADD COLUMN batch_id INTEGER DEFAULT NULL;
ALTER TABLE receipt_items ADD COLUMN expiry_date TEXT DEFAULT NULL;

-- Create a view for expiring products (products expiring in next 30 days)
CREATE VIEW IF NOT EXISTS expiring_products AS
SELECT 
    sb.id as batch_id,
    p.id as product_id,
    p.sku,
    p.name as product_name,
    sb.batch_number,
    sb.manufacturing_date,
    sb.expiry_date,
    sb.quantity,
    CAST((julianday(sb.expiry_date) - julianday('now')) AS INTEGER) as days_until_expiry,
    CASE 
        WHEN julianday(sb.expiry_date) < julianday('now') THEN 'expired'
        WHEN julianday(sb.expiry_date) <= julianday('now', '+7 days') THEN 'critical'
        WHEN julianday(sb.expiry_date) <= julianday('now', '+30 days') THEN 'warning'
        ELSE 'good'
    END as status
FROM stock_batches sb
JOIN products p ON sb.product_id = p.id
WHERE p.has_expiry = 1 AND sb.quantity > 0
ORDER BY sb.expiry_date ASC;

-- Create a view for expired products
CREATE VIEW IF NOT EXISTS expired_products AS
SELECT * FROM expiring_products WHERE status = 'expired';
