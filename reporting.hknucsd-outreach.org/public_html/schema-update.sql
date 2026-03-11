-- Update users table to add role and allowed_sections
-- Only add columns if they don't exist
ALTER TABLE users ADD COLUMN allowed_sections JSON DEFAULT NULL AFTER role;
ALTER TABLE users ADD COLUMN display_name VARCHAR(255) DEFAULT NULL AFTER username;

-- Create indexes for better performance
CREATE INDEX idx_users_role ON users(role);

-- Example data for roles:
-- admin: can do anything
-- analyst: can view assigned sections (stored as JSON array in allowed_sections)
-- viewer: can only view saved reports

-- Example allowed_sections for analyst:
-- ["performance", "behavioral"] or ["performance"] or ["behavioral", "engagement"]
