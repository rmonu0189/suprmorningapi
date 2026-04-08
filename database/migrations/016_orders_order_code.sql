-- Add human-readable order code for staff operations (packing/delivery).

ALTER TABLE orders
    ADD COLUMN order_code VARCHAR(16) NULL AFTER id;

CREATE UNIQUE INDEX uq_orders_order_code ON orders (order_code);

