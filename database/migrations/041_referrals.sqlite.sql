ALTER TABLE users ADD COLUMN referral_code VARCHAR(16) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_referral_code ON users(referral_code);

CREATE TABLE IF NOT EXISTS referrals (
    id CHAR(36) NOT NULL,
    referrer_user_id CHAR(36) NOT NULL,
    referred_user_id CHAR(36) NOT NULL,
    referral_code VARCHAR(16) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    reward_amount DECIMAL(12, 2) NOT NULL DEFAULT 50.00,
    qualifying_order_id CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE (referred_user_id),
    FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (qualifying_order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_referrals_referrer_status ON referrals(referrer_user_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_referrals_order ON referrals(qualifying_order_id);
