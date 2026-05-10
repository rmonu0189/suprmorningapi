-- Clear one user's order/payment related data, and clear wallet data for all users.
-- Safe to run multiple times.
--
-- Keeps:
--   - users, addresses, catalog (brands/products/variants/inventory), pages, coupons,
--     files, taxonomy, cart_charges (seed/config), subscriptions
--
-- Wipes:
--   For @target_user_id only:
--   - carts + cart_items
--   - orders + order_items
--   - order_status_events (order fulfillment audit)
--   - delivery_item_checks (packing checklist)
--   - order ratings
--   - order support queries + messages
--   - order payments + matching payment_events
--   - subscription_order_generation rows for that user
--   - qualifying_order_id links on referrals for that user's orders
--
--   For all users:
--   - wallets
--   - wallet_holds
--   - wallet_transactions
--   - wallet_topups + matching payment_events
--
-- Usage (from api/database):
--   1) Replace the value below with the user id you want to clear.
--   mysql -u USER -p DB_NAME < api/database/scripts/clear_commerce_data.sql

SET @target_user_id = 'PASTE_USER_ID_HERE';

START TRANSACTION;

SET @target_user_id = NULLIF(TRIM(@target_user_id), '');
SET @target_user_id = NULLIF(@target_user_id, 'PASTE_USER_ID_HERE');

-- Hard guard: fail before any destructive work if @target_user_id was not set.
DROP TEMPORARY TABLE IF EXISTS tmp_clear_guard;
CREATE TEMPORARY TABLE tmp_clear_guard (
    target_user_id CHAR(36) NOT NULL
) ENGINE=MEMORY;

INSERT INTO tmp_clear_guard (target_user_id)
SELECT @target_user_id;

DROP TEMPORARY TABLE IF EXISTS tmp_clear_user_orders;
CREATE TEMPORARY TABLE tmp_clear_user_orders (
    id CHAR(36) NOT NULL,
    gateway_order_id VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_tmp_clear_user_orders_gateway (gateway_order_id)
) ENGINE=MEMORY;

INSERT INTO tmp_clear_user_orders (id, gateway_order_id)
SELECT id, gateway_order_id
FROM orders
WHERE user_id = @target_user_id;

DROP TEMPORARY TABLE IF EXISTS tmp_clear_gateway_order_ids;
CREATE TEMPORARY TABLE tmp_clear_gateway_order_ids (
    gateway_order_id VARCHAR(255) NOT NULL,
    PRIMARY KEY (gateway_order_id)
) ENGINE=MEMORY;

-- Payment event rows are not FK-linked, so capture gateway ids before deleting payments/topups.
INSERT IGNORE INTO tmp_clear_gateway_order_ids (gateway_order_id)
SELECT gateway_order_id
FROM tmp_clear_user_orders
WHERE gateway_order_id IS NOT NULL AND gateway_order_id <> '';

INSERT IGNORE INTO tmp_clear_gateway_order_ids (gateway_order_id)
SELECT p.gateway_order_id
FROM payments p
INNER JOIN tmp_clear_user_orders o ON o.id = p.order_id
WHERE p.gateway_order_id IS NOT NULL AND p.gateway_order_id <> '';

INSERT IGNORE INTO tmp_clear_gateway_order_ids (gateway_order_id)
SELECT gateway_order_id
FROM wallet_topups
WHERE gateway_order_id IS NOT NULL AND gateway_order_id <> '';

-- Clear whole wallet domain for all users first. This also prevents stale locked_balance.
DELETE FROM wallet_holds;
DELETE FROM wallet_transactions;
DELETE FROM wallet_topups;
DELETE FROM wallets;

-- Clear uncoupled payment webhook/event rows for the target user's orders and all wallet topups.
DELETE pe
FROM payment_events pe
INNER JOIN tmp_clear_gateway_order_ids g ON g.gateway_order_id = pe.gateway_order_id;

-- Child order tables first (FK-safe and explicit, even where ON DELETE CASCADE exists).
DELETE osm
FROM order_support_messages osm
INNER JOIN order_support_queries osq ON osq.id = osm.query_id
INNER JOIN tmp_clear_user_orders o ON o.id = osq.order_id;

DELETE osq
FROM order_support_queries osq
INNER JOIN tmp_clear_user_orders o ON o.id = osq.order_id;

DELETE oir
FROM order_item_ratings oir
INNER JOIN tmp_clear_user_orders o ON o.id = oir.order_id;

DELETE odr
FROM order_delivery_ratings odr
INNER JOIN tmp_clear_user_orders o ON o.id = odr.order_id;

DELETE ose
FROM order_status_events ose
INNER JOIN tmp_clear_user_orders o ON o.id = ose.order_id;

DELETE dic
FROM delivery_item_checks dic
INNER JOIN tmp_clear_user_orders o ON o.id = dic.order_id;

DELETE p
FROM payments p
INNER JOIN tmp_clear_user_orders o ON o.id = p.order_id;

DELETE oi
FROM order_items oi
INNER JOIN tmp_clear_user_orders o ON o.id = oi.order_id;

-- Remove or unlink order references that are ON DELETE SET NULL in the schema.
DELETE sog
FROM subscription_order_generation sog
LEFT JOIN tmp_clear_user_orders o ON o.id = sog.order_id
WHERE sog.user_id = @target_user_id OR o.id IS NOT NULL;

UPDATE referrals r
INNER JOIN tmp_clear_user_orders o ON o.id = r.qualifying_order_id
SET r.qualifying_order_id = NULL;

DELETE ord
FROM orders ord
INNER JOIN tmp_clear_user_orders o ON o.id = ord.id;

DELETE ci
FROM cart_items ci
INNER JOIN carts c ON c.id = ci.cart_id
WHERE c.user_id = @target_user_id;

DELETE FROM carts
WHERE user_id = @target_user_id;

DROP TEMPORARY TABLE IF EXISTS tmp_clear_gateway_order_ids;
DROP TEMPORARY TABLE IF EXISTS tmp_clear_user_orders;
DROP TEMPORARY TABLE IF EXISTS tmp_clear_guard;

COMMIT;
