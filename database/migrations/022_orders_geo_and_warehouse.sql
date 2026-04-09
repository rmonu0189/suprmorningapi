-- Orders: snapshot geo + assignable warehouse.
-- - orders.latitude/longitude: snapshot from addresses at order placement time
-- - orders.warehouse_id: assigned fulfillment warehouse (INT)

ALTER TABLE orders
    ADD COLUMN latitude DECIMAL(10, 7) NOT NULL DEFAULT 0 AFTER postal_code,
    ADD COLUMN longitude DECIMAL(10, 7) NOT NULL DEFAULT 0 AFTER latitude,
    ADD COLUMN warehouse_id INT NULL AFTER address_label;

-- Best-effort backfill from addresses table for existing orders.
UPDATE orders o
LEFT JOIN addresses a ON a.id = o.address_id
SET
    o.latitude = COALESCE(a.latitude, 0),
    o.longitude = COALESCE(a.longitude, 0)
WHERE (o.latitude = 0 OR o.longitude = 0)
  AND a.id IS NOT NULL;

ALTER TABLE orders
    ADD KEY idx_orders_warehouse (warehouse_id),
    ADD CONSTRAINT fk_orders_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE SET NULL;

