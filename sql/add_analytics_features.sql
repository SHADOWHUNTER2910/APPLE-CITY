-- Add user tracking to receipts
ALTER TABLE receipts ADD COLUMN created_by INTEGER;
ALTER TABLE receipts ADD COLUMN created_by_username VARCHAR(255);

-- Update existing receipts to have admin as creator (for existing data)
UPDATE receipts SET created_by = 1, created_by_username = 'admin' WHERE created_by IS NULL;

-- Add indexes for better performance on analytics queries
CREATE INDEX IF NOT EXISTS idx_receipts_created_at ON receipts(created_at);
CREATE INDEX IF NOT EXISTS idx_receipts_created_by ON receipts(created_by);
CREATE INDEX IF NOT EXISTS idx_receipt_items_product_id ON receipt_items(product_id);