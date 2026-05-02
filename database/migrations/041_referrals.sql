ALTER TABLE users
    ADD COLUMN referral_code VARCHAR(16) NULL AFTER full_name,
    ADD UNIQUE KEY uq_users_referral_code (referral_code);

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
    UNIQUE KEY uq_referrals_referred_user (referred_user_id),
    KEY idx_referrals_referrer_status (referrer_user_id, status, created_at),
    KEY idx_referrals_order (qualifying_order_id),
    CONSTRAINT fk_referrals_referrer FOREIGN KEY (referrer_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_referrals_referred FOREIGN KEY (referred_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_referrals_order FOREIGN KEY (qualifying_order_id) REFERENCES orders (id) ON DELETE SET NULL
);
