-- Subscriptions: recurring product subscriptions for customers.

CREATE TABLE IF NOT EXISTS subscriptions (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    variant_id CHAR(36) NOT NULL,
    frequency VARCHAR(16) NOT NULL, -- daily|weekly|alternate
    quantity INT NOT NULL DEFAULT 1,
    weekly_schedule JSON NULL,      -- [{day:0..6, quantity:int}, ...] for weekly
    start_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscriptions_user_created (user_id, created_at),
    KEY idx_subscriptions_variant_created (variant_id, created_at),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_variant FOREIGN KEY (variant_id) REFERENCES variants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
