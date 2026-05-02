ALTER TABLE orders
    ADD COLUMN coupon_code VARCHAR(128) NULL AFTER total_charges,
    ADD COLUMN coupon_discount DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER coupon_code;

CREATE INDEX idx_orders_coupon_created ON orders (coupon_code, created_at);
