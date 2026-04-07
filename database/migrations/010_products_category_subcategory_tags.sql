-- Add structured product fields (optional): category, subcategory, tags.
-- Kept alongside existing `metadata` for backwards compatibility.

ALTER TABLE products
  ADD COLUMN tags JSON NULL;

-- Helpful for filtering in admin/catalog screens.
CREATE INDEX idx_products_category ON products (category);
CREATE INDEX idx_products_subcategory ON products (subcategory);

