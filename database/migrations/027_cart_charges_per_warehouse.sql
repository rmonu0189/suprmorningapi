-- Cart charges: scope charges per warehouse (warehouse_id + charge fields).
-- warehouse_id=0 is the legacy/global bucket.

ALTER TABLE cart_charges
    ADD COLUMN warehouse_id INT NOT NULL DEFAULT 0 AFTER id;

ALTER TABLE cart_charges
    ADD KEY idx_cart_charges_warehouse (warehouse_id),
    ADD KEY idx_cart_charges_wh_index (warehouse_id, charge_index);

