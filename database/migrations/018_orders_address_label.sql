-- Snapshot address label onto orders to keep orders immutable
-- Source of truth: orders.address_label (do NOT depend on addresses.label at read time)

ALTER TABLE orders
    ADD COLUMN address_label VARCHAR(64) NULL AFTER address_id;

-- Best-effort backfill for existing orders.
-- This snapshots the current addresses.label at migration time.
UPDATE orders o
LEFT JOIN addresses a ON a.id = o.address_id
SET o.address_label = a.label
WHERE (o.address_label IS NULL OR o.address_label = '')
  AND a.label IS NOT NULL
  AND a.label != '';

