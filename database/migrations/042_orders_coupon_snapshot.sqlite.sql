ALTER TABLE orders ADD COLUMN coupon_code TEXT NULL;
ALTER TABLE orders ADD COLUMN coupon_discount REAL NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_orders_coupon_created ON orders (coupon_code, created_at);
