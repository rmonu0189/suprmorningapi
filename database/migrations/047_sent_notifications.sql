CREATE TABLE IF NOT EXISTS sent_notifications (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(512) NULL,
    body TEXT NOT NULL,
    data JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sent_notifications_user (user_id),
    KEY idx_sent_notifications_created (created_at),
    CONSTRAINT fk_sent_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
