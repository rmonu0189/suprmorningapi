-- Inventory: track stock per warehouse (warehouse_id + variant_id).
-- Note: warehouse_id=0 is a fallback legacy/global bucket.

ALTER TABLE inventory
    ADD COLUMN warehouse_id INT NOT NULL DEFAULT 0 AFTER created_at;

-- Backfill: if at least one warehouse exists, move legacy rows to the first warehouse.
UPDATE inventory
SET warehouse_id = COALESCE((SELECT MIN(id) FROM warehouses), 0)
WHERE warehouse_id = 0;

-- Keep an index with variant_id as the leftmost column for the FK constraint.
ALTER TABLE inventory
    ADD KEY idx_inventory_variant (variant_id);

ALTER TABLE inventory
    DROP INDEX uq_inventory_variant,
    ADD UNIQUE KEY uq_inventory_wh_variant (warehouse_id, variant_id),
    ADD KEY idx_inventory_warehouse (warehouse_id);

ALTER TABLE inventory_movements
    ADD COLUMN warehouse_id INT NOT NULL DEFAULT 0 AFTER created_at;

UPDATE inventory_movements
SET warehouse_id = COALESCE((SELECT MIN(id) FROM warehouses), 0)
WHERE warehouse_id = 0;

ALTER TABLE inventory_movements
    ADD KEY idx_inventory_movements_warehouse (warehouse_id);

