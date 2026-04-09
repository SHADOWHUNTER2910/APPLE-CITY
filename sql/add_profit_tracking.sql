-- Add profit tracking to the Stock Manager system
-- This migration adds cost price and profit calculation support

-- Add cost_price to product_units table (each unit can have different cost)
ALTER TABLE product_units ADD COLUMN cost_price REAL DEFAULT 0.00;

-- Add profit-related columns to receipt_items table
ALTER TABLE receipt_items ADD COLUMN cost_price REAL DEFAULT 0.00;
ALTER TABLE receipt_items ADD COLUMN profit REAL DEFAULT 0.00;

-- Add profit summary columns to receipts table
ALTER TABLE receipts ADD COLUMN total_cost REAL DEFAULT 0.00;
ALTER TABLE receipts ADD COLUMN total_profit REAL DEFAULT 0.00;
ALTER TABLE receipts ADD COLUMN profit_margin REAL DEFAULT 0.00;

-- Create index for faster profit queries
CREATE INDEX IF NOT EXISTS idx_product_units_cost_price ON product_units(cost_price);
CREATE INDEX IF NOT EXISTS idx_receipt_items_profit ON receipt_items(profit);
CREATE INDEX IF NOT EXISTS idx_receipts_profit ON receipts(total_profit);

-- Create view for product profitability analysis (by unit)
CREATE VIEW IF NOT EXISTS product_unit_profitability AS
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
    END as profit_margin_percent,
    pu.is_base_unit
FROM product_units pu
JOIN products p ON pu.product_id = p.id
WHERE p.id != 0 AND p.sku != 'DELETED';

-- Create view for low profit units (margin < 10%)
CREATE VIEW IF NOT EXISTS low_profit_units AS
SELECT * FROM product_unit_profitability 
WHERE profit_margin_percent < 10 AND profit_margin_percent >= 0
ORDER BY profit_margin_percent ASC;

-- Create view for negative profit units (selling below cost)
CREATE VIEW IF NOT EXISTS negative_profit_units AS
SELECT * FROM product_unit_profitability 
WHERE profit_margin_percent < 0
ORDER BY profit_margin_percent ASC;
