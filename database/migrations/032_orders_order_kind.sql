-- Orders: identify how the order was created (user checkout vs subscription auto-gen).

ALTER TABLE orders
    ADD COLUMN order_kind VARCHAR(32) NOT NULL DEFAULT 'user' AFTER gateway_name;

-- Helpful index for reporting/filtering by order kind.
ALTER TABLE orders
    ADD KEY idx_orders_kind_date (order_kind, delivery_date);

