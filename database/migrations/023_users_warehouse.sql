-- Users: optional warehouse assignment (for staff scoping).

ALTER TABLE users
    ADD COLUMN warehouse_id INT NULL AFTER role;

ALTER TABLE users
    ADD KEY idx_users_warehouse (warehouse_id),
    ADD CONSTRAINT fk_users_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE SET NULL;

