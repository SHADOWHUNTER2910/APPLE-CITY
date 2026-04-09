-- Add status column to users table
ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active';

-- Update existing users to have active status
UPDATE users SET status = 'active' WHERE status IS NULL;