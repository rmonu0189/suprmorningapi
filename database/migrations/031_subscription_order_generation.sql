-- Track per-user generation status for subscription orders (admin-triggered).

CREATE TABLE IF NOT EXISTS subscription_order_generation (
    delivery_date DATE NOT NULL,
    user_id CHAR(36) NOT NULL,
    run_id CHAR(36) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending', -- pending|success|failed|skipped_no_items|skipped_no_address
    order_id CHAR(36) NULL,
    error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_date, user_id),
    KEY idx_sog_delivery_status (delivery_date, status),
    KEY idx_sog_run (run_id),
    CONSTRAINT fk_sog_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sog_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

