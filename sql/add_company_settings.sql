-- Add company settings table
CREATE TABLE IF NOT EXISTS company_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default company settings
INSERT OR IGNORE INTO company_settings (setting_key, setting_value) VALUES
('company_name', 'Your Company Name'),
('company_subtitle', 'Business Description'),
('company_location', 'Location: Your Business Address'),
('company_phone', 'TEL: Your Phone Number'),
('company_email', ''),
('company_website', ''),
('company_logo', 'assets/logo.jpg');
