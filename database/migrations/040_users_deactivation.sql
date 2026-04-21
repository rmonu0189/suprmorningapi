ALTER TABLE users
    ADD COLUMN deactivated_at DATETIME NULL AFTER updated_at,
    ADD COLUMN deactivation_reason_code VARCHAR(64) NULL AFTER deactivated_at,
    ADD COLUMN deactivation_reason_text TEXT NULL AFTER deactivation_reason_code,
    ADD COLUMN original_phone VARCHAR(20) NULL AFTER phone,
    ADD COLUMN original_email VARCHAR(255) NULL AFTER email;
