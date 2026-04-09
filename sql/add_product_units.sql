-- Add product units of measure support
-- This migration adds multi-unit support to products

-- Create product_units table
CREATE TABLE IF NOT EXISTS product_units (
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
);

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_product_units_product ON product_units(product_id);
CREATE INDEX IF NOT EXISTS idx_product_units_base ON product_units(product_id, is_base_unit);

-- Add columns to receipt_items for unit tracking
ALTER TABLE receipt_items ADD COLUMN unit_id INTEGER DEFAULT NULL;
ALTER TABLE receipt_items ADD COLUMN unit_name TEXT DEFAULT NULL;
ALTER TABLE receipt_items ADD COLUMN unit_abbreviation TEXT DEFAULT NULL;
ALTER TABLE receipt_items ADD COLUMN quantity_in_base_unit REAL DEFAULT NULL;

-- Add default_unit_id to products table
ALTER TABLE products ADD COLUMN default_unit_id INTEGER DEFAULT NULL;

-- Migrate existing products to have a default "Unit" 
-- This will be handled by the application on first run
