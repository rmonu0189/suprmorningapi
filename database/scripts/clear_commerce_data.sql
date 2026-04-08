-- Clear all cart + order related data (fresh commerce DB)
-- Safe to run multiple times.
--
-- Keeps:
--   - users, catalog (brands/products/variants/inventory), pages, coupons, files, taxonomy, cart_charges (seed/config)
--
-- Wipes:
--   - carts + cart_items
--   - orders + order_items
--   - delivery_item_checks (packing checklist)
--   - payments + payment_events
--
-- Usage (from api/database):
--   mysql -u USER -p DB_NAME < api/database/scripts/clear_commerce_data.sql

START TRANSACTION;

-- If anything fails mid-way, rollback instead of leaving half-cleared state.

-- Child tables first (FK-safe).
DELETE FROM delivery_item_checks;
DELETE FROM order_items;
DELETE FROM payments;
DELETE FROM payment_events;

DELETE FROM orders;

DELETE FROM cart_items;
DELETE FROM carts;

COMMIT;

