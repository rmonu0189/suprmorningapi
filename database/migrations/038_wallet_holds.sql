-- Wallet holds for split checkout (wallet + Razorpay). Run after orders exist.

ALTER TABLE wallets
    ADD COLUMN locked_balance DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER balance;

CREATE TABLE IF NOT EXISTS wallet_holds (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallet_holds_order (order_id),
    KEY idx_wallet_holds_user (user_id),
    KEY idx_wallet_holds_status (status),
    CONSTRAINT fk_wallet_holds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_holds_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
