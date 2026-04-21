ALTER TABLE users ADD COLUMN deactivated_at DATETIME NULL;
ALTER TABLE users ADD COLUMN deactivation_reason_code VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN deactivation_reason_text TEXT NULL;
ALTER TABLE users ADD COLUMN original_phone VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN original_email VARCHAR(255) NULL;
