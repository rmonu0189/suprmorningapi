CREATE TABLE IF NOT EXISTS push_notification_devices (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'expo',
    token TEXT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    platform VARCHAR(32) NULL,
    device_id VARCHAR(128) NULL,
    device_name VARCHAR(255) NULL,
    app_version VARCHAR(64) NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_notification_devices_token_hash (token_hash),
    KEY idx_push_notification_devices_user (user_id),
    KEY idx_push_notification_devices_provider (provider),
    CONSTRAINT fk_push_notification_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
