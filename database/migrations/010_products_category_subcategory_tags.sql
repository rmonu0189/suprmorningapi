-- Add structured product fields (optional): category, subcategory, tags.
-- Kept alongside existing `metadata` for backwards compatibility.

ALTER TABLE products
  ADD COLUMN category VARCHAR(255) NULL AFTER description,
  ADD COLUMN subcategory VARCHAR(255) NULL AFTER category,
  ADD COLUMN tags JSON NULL AFTER subcategory;

-- Helpful for filtering in admin/catalog screens.
CREATE INDEX idx_products_category ON products (category);
CREATE INDEX idx_products_subcategory ON products (subcategory);

