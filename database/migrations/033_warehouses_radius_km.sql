-- Add service radius for warehouses (in kilometers).
ALTER TABLE warehouses
    ADD COLUMN radius_km DECIMAL(10, 2) NOT NULL DEFAULT 5.00 AFTER longitude;

