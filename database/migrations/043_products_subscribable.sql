-- Add is_subscribable column to products table.
-- Default is 1 (true) to ensure existing products remain subscribable.

ALTER TABLE products ADD COLUMN is_subscribable TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
