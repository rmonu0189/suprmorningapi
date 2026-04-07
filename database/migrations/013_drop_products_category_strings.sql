-- Drop legacy free-text category/subcategory columns from products.
-- After moving to products.category_id / products.subcategory_id, these are no longer needed.

ALTER TABLE products
  DROP INDEX idx_products_category,
  DROP INDEX idx_products_subcategory,
  DROP COLUMN category,
  DROP COLUMN subcategory;

