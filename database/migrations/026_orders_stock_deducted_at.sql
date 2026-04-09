-- Orders: mark when inventory was deducted (packed).

ALTER TABLE orders
    ADD COLUMN stock_deducted_at DATETIME NULL AFTER delivered_at;

