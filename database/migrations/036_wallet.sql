CREATE TABLE IF NOT EXISTS wallets (
    user_id CHAR(36) NOT NULL,
    balance DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(8) NOT NULL DEFAULT 'INR',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    order_id CHAR(36) NULL,
    type VARCHAR(16) NOT NULL, -- credit|debit
    source VARCHAR(32) NOT NULL, -- topup|order|subscription_order|refund|adjustment
    amount DECIMAL(12, 2) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'success',
    reference_id VARCHAR(255) NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wallet_transactions_user_created (user_id, created_at),
    KEY idx_wallet_transactions_order (order_id),
    CONSTRAINT fk_wallet_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_transactions_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
);
