-- Coupons table (admin-managed). Mirrors SMAdmin coupon fields.

CREATE TABLE IF NOT EXISTS coupons (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    code VARCHAR(128) NOT NULL,
    discount_type VARCHAR(32) NOT NULL, -- 'fixed' | 'percentage'
    discount_value DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    min_cart_value DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    starts_at DATE NOT NULL,
    expires_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    max_discount DECIMAL(12, 2) NULL,
    usage_limit INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupons_code (code),
    KEY idx_coupons_active (is_active),
    KEY idx_coupons_starts (starts_at),
    KEY idx_coupons_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

