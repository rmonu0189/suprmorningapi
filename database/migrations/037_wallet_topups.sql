CREATE TABLE IF NOT EXISTS wallet_topups (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'INR',
    gateway_name VARCHAR(32) NOT NULL DEFAULT 'razorpay',
    gateway_order_id VARCHAR(255) NOT NULL,
    gateway_payment_id VARCHAR(255) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'created', -- created|processing|success|failed
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    credited_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallet_topups_gateway_order (gateway_order_id),
    UNIQUE KEY uq_wallet_topups_gateway_payment (gateway_payment_id),
    KEY idx_wallet_topups_user_created (user_id, created_at),
    CONSTRAINT fk_wallet_topups_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
