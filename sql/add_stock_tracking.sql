-- Add columns to track initial stock and calculate total received/sold
-- These columns will be added to the stock table

-- For SQLite
ALTER TABLE stock ADD COLUMN initial_quantity INTEGER DEFAULT 0;

-- Create a view to calculate total received and sold from stock_movements
CREATE VIEW IF NOT EXISTS stock_analytics AS
SELECT 
    s.product_id,
    s.quantity as current_quantity,
    s.initial_quantity,
    COALESCE(SUM(CASE WHEN sm.movement_type = 'addition' THEN sm.quantity ELSE 0 END), 0) as total_received,
    COALESCE(SUM(CASE WHEN sm.movement_type = 'deduction' THEN sm.quantity ELSE 0 END), 0) as total_sold
FROM stock s
LEFT JOIN stock_movements sm ON s.product_id = sm.product_id
GROUP BY s.product_id, s.quantity, s.initial_quantity;
