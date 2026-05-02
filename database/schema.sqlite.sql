-- SQLite schema for local development (minimal subset).
-- This is only used when DB_CONNECTION=sqlite.

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- Commerce (subset)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cart_charges (
  id TEXT PRIMARY KEY,
  warehouse_id INTEGER NOT NULL DEFAULT 0,
  charge_index INTEGER NOT NULL DEFAULT 0,
  title TEXT NOT NULL,
  amount REAL NOT NULL,
  min_order_value REAL NULL,
  info TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_cart_charges_index ON cart_charges(charge_index);
CREATE INDEX IF NOT EXISTS idx_cart_charges_warehouse ON cart_charges(warehouse_id);
CREATE INDEX IF NOT EXISTS idx_cart_charges_wh_index ON cart_charges(warehouse_id, charge_index);

-- Used by OrderPlacementService::resolveChargeWarehouse (CartChargeRepository + nearest warehouse).
CREATE TABLE IF NOT EXISTS warehouses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uuid TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL UNIQUE,
  address_line_1 TEXT NOT NULL,
  address_line_2 TEXT NULL,
  area TEXT NULL,
  city TEXT NOT NULL,
  state TEXT NOT NULL,
  country TEXT NOT NULL,
  postal_code TEXT NOT NULL,
  latitude REAL NOT NULL DEFAULT 0,
  longitude REAL NOT NULL DEFAULT 0,
  radius_km REAL NOT NULL DEFAULT 5,
  status INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_warehouses_status ON warehouses(status);

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  phone TEXT NOT NULL,
  country_code TEXT NOT NULL DEFAULT '+91',
  email TEXT NULL UNIQUE,
  full_name TEXT NULL,
  referral_code TEXT NULL UNIQUE,
  is_active INTEGER NOT NULL DEFAULT 1,
  role TEXT NOT NULL DEFAULT 'user',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_country_phone ON users(country_code, phone);

CREATE TABLE IF NOT EXISTS phone_otp_challenges (
  id TEXT PRIMARY KEY,
  phone TEXT NOT NULL UNIQUE,
  salt_hex TEXT NOT NULL,
  code_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  user_agent TEXT NULL,
  device_label TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Catalog + taxonomy tables used by admin endpoints.
CREATE TABLE IF NOT EXISTS brands (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL,
  about TEXT NULL,
  logo TEXT NULL,
  status INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS categories (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL UNIQUE,
  slug TEXT NULL UNIQUE,
  status INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS subcategories (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  category_id TEXT NOT NULL,
  name TEXT NOT NULL,
  slug TEXT NULL,
  status INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  UNIQUE (category_id, name),
  UNIQUE (category_id, slug),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS products (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  brand_id TEXT NOT NULL,
  category_id TEXT NULL,
  subcategory_id TEXT NULL,
  name TEXT NOT NULL,
  description TEXT NULL,
  tags TEXT NULL,
  status INTEGER NOT NULL DEFAULT 1,
  metadata TEXT NULL,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS variants (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  product_id TEXT NOT NULL,
  name TEXT NOT NULL,
  sku TEXT NOT NULL UNIQUE,
  price REAL NOT NULL,
  mrp REAL NOT NULL,
  images TEXT NULL,
  metadata TEXT NULL,
  status INTEGER NOT NULL DEFAULT 1,
  discount_tag TEXT NULL,
  tags TEXT NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS variant_tag_master (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL UNIQUE,
  status INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_variant_tag_master_status_sort ON variant_tag_master(status, sort_order, name);

CREATE TABLE IF NOT EXISTS inventory (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  warehouse_id INTEGER NOT NULL DEFAULT 0,
  variant_id TEXT NOT NULL UNIQUE,
  quantity INTEGER NOT NULL DEFAULT 0,
  reserved_quantity INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (variant_id) REFERENCES variants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_inventory_wh_variant ON inventory(warehouse_id, variant_id);

CREATE TABLE IF NOT EXISTS inventory_movements (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  warehouse_id INTEGER NOT NULL DEFAULT 0,
  variant_id TEXT NOT NULL,
  delta_quantity INTEGER NOT NULL,
  note TEXT NULL,
  created_by TEXT NULL,
  FOREIGN KEY (variant_id) REFERENCES variants(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Order code counter (must work with SQLite increment; see OrderRepository::nextOrderCode).
CREATE TABLE IF NOT EXISTS order_code_sequence (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  last_number INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO order_code_sequence (id, last_number) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS carts (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  coupon_code TEXT NULL,
  discount_type TEXT NULL,
  discount_value TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_carts_user_status ON carts(user_id, status);

CREATE TABLE IF NOT EXISTS addresses (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  label TEXT NOT NULL,
  recipient_name TEXT NOT NULL,
  phone TEXT NOT NULL,
  address_line_1 TEXT NOT NULL,
  address_line_2 TEXT NULL,
  area TEXT NULL,
  city TEXT NOT NULL,
  state TEXT NOT NULL,
  country TEXT NOT NULL,
  postal_code TEXT NOT NULL,
  latitude REAL NOT NULL DEFAULT 0,
  longitude REAL NOT NULL DEFAULT 0,
  is_default INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_addresses_user ON addresses(user_id);

CREATE TABLE IF NOT EXISTS cart_items (
  id TEXT PRIMARY KEY,
  cart_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  variant_id TEXT NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  unit_price REAL NOT NULL,
  unit_mrp REAL NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (variant_id) REFERENCES variants(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_cart_items_cart ON cart_items(cart_id);

CREATE TABLE IF NOT EXISTS orders (
  id TEXT PRIMARY KEY,
  order_code TEXT NULL UNIQUE,
  user_id TEXT NOT NULL,
  cart_id TEXT NULL,
  address_id TEXT NULL,
  address_label TEXT NULL,
  warehouse_id INTEGER NULL,
  order_status TEXT NOT NULL DEFAULT 'created',
  payment_status TEXT NOT NULL DEFAULT 'pending',
  delivery_date TEXT NULL,
  delivery_slot TEXT NULL,
  delivery_type TEXT NULL,
  total_mrp REAL NOT NULL DEFAULT 0,
  tax REAL NOT NULL DEFAULT 0,
  delivery_fee REAL NOT NULL DEFAULT 0,
  grand_total REAL NOT NULL,
  currency TEXT NOT NULL DEFAULT 'INR',
  recipient_name TEXT NOT NULL,
  recipient_phone TEXT NOT NULL,
  full_address TEXT NOT NULL,
  city TEXT NOT NULL,
  state TEXT NOT NULL,
  country TEXT NOT NULL,
  postal_code TEXT NOT NULL,
  latitude REAL NOT NULL DEFAULT 0,
  longitude REAL NOT NULL DEFAULT 0,
  total_price REAL NOT NULL,
  total_charges REAL NOT NULL DEFAULT 0,
  coupon_code TEXT NULL,
  coupon_discount REAL NOT NULL DEFAULT 0,
  gateway_order_id TEXT NULL,
  gateway_name TEXT NULL DEFAULT 'razorpay',
  order_kind TEXT NOT NULL DEFAULT 'user',
  charges_metadata TEXT NULL,
  delivered_at TEXT NULL,
  stock_deducted_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE SET NULL,
  FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_orders_gateway ON orders(gateway_order_id);
CREATE INDEX IF NOT EXISTS idx_orders_coupon_created ON orders(coupon_code, created_at);
CREATE INDEX IF NOT EXISTS idx_orders_kind_date ON orders(order_kind, delivery_date);

CREATE TABLE IF NOT EXISTS order_items (
  id TEXT PRIMARY KEY,
  order_id TEXT NOT NULL,
  image TEXT NOT NULL DEFAULT '',
  brand_name TEXT NOT NULL,
  product_name TEXT NOT NULL,
  variant_name TEXT NOT NULL,
  quantity INTEGER NOT NULL,
  unit_price REAL NOT NULL,
  unit_mrp REAL NOT NULL,
  sku TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);

CREATE TABLE IF NOT EXISTS payments (
  id TEXT PRIMARY KEY,
  order_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  gateway TEXT NOT NULL,
  gateway_order_id TEXT NOT NULL,
  amount REAL NOT NULL,
  currency TEXT NOT NULL DEFAULT 'INR',
  status TEXT NOT NULL DEFAULT 'initiated',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(order_id);
CREATE INDEX IF NOT EXISTS idx_payments_gateway_order ON payments(gateway_order_id);

CREATE TABLE IF NOT EXISTS referrals (
  id TEXT PRIMARY KEY,
  referrer_user_id TEXT NOT NULL,
  referred_user_id TEXT NOT NULL UNIQUE,
  referral_code TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  reward_amount REAL NOT NULL DEFAULT 50.00,
  qualifying_order_id TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TEXT NULL,
  FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (qualifying_order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_referrals_referrer_status ON referrals(referrer_user_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_referrals_order ON referrals(qualifying_order_id);

CREATE TABLE IF NOT EXISTS wallet_holds (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  order_id TEXT NOT NULL UNIQUE,
  amount REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_wallet_holds_user ON wallet_holds(user_id);
CREATE INDEX IF NOT EXISTS idx_wallet_holds_status ON wallet_holds(status);

CREATE TABLE IF NOT EXISTS subscriptions (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  variant_id TEXT NOT NULL,
  frequency TEXT NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  weekly_schedule TEXT NULL,
  start_date TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (variant_id) REFERENCES variants(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_subscriptions_user_created ON subscriptions(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_subscriptions_variant_created ON subscriptions(variant_id, created_at);

CREATE TABLE IF NOT EXISTS wallets (
  user_id TEXT PRIMARY KEY,
  balance REAL NOT NULL DEFAULT 0,
  locked_balance REAL NOT NULL DEFAULT 0,
  currency TEXT NOT NULL DEFAULT 'INR',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  order_id TEXT NULL,
  type TEXT NOT NULL,
  source TEXT NOT NULL,
  amount REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'success',
  reference_id TEXT NULL,
  note TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_wallet_transactions_user_created ON wallet_transactions(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_order ON wallet_transactions(order_id);

CREATE TABLE IF NOT EXISTS wallet_topups (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  amount REAL NOT NULL,
  currency TEXT NOT NULL DEFAULT 'INR',
  gateway_name TEXT NOT NULL DEFAULT 'razorpay',
  gateway_order_id TEXT NOT NULL UNIQUE,
  gateway_payment_id TEXT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'created',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  credited_at TEXT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_wallet_topups_user_created ON wallet_topups(user_id, created_at);

-- Admin-triggered subscription order generation progress per user.
CREATE TABLE IF NOT EXISTS subscription_order_generation (
  delivery_date TEXT NOT NULL,
  user_id TEXT NOT NULL,
  run_id TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  order_id TEXT NULL,
  error TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_date, user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_sog_delivery_status ON subscription_order_generation(delivery_date, status);
CREATE INDEX IF NOT EXISTS idx_sog_run ON subscription_order_generation(run_id);

CREATE TABLE IF NOT EXISTS order_item_ratings (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  order_id TEXT NOT NULL,
  order_item_id TEXT NOT NULL,
  rating INTEGER NOT NULL,
  feedback TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, order_item_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_delivery_ratings (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  order_id TEXT NOT NULL,
  delivery_partner_user_id TEXT NULL,
  rating INTEGER NOT NULL,
  feedback TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, order_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (delivery_partner_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS order_support_queries (
  id TEXT PRIMARY KEY,
  order_id TEXT NOT NULL UNIQUE,
  user_id TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  subject TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TEXT NULL,
  resolved_by TEXT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_order_support_queries_status ON order_support_queries(status);
CREATE INDEX IF NOT EXISTS idx_order_support_queries_user ON order_support_queries(user_id);

CREATE TABLE IF NOT EXISTS order_support_messages (
  id TEXT PRIMARY KEY,
  query_id TEXT NOT NULL,
  sender_user_id TEXT NOT NULL,
  sender_role TEXT NOT NULL,
  message TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (query_id) REFERENCES order_support_queries(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_order_support_messages_query ON order_support_messages(query_id, created_at);
CREATE INDEX IF NOT EXISTS idx_order_support_messages_sender ON order_support_messages(sender_user_id);

-- Seed an admin user for local OTP login (OTP_TEST_PHONES includes 919109322140).
INSERT OR IGNORE INTO users (id, phone, country_code, email, full_name, is_active, role)
VALUES ('00000000-0000-4000-8000-000000000001', '9109322140', '+91', 'admin@local.test', 'Local Admin', 1, 'admin');

-- Seed a couple of orders for the dashboard recent orders table.
INSERT OR IGNORE INTO orders (
  id, user_id, order_status, payment_status,
  total_mrp, tax, delivery_fee, grand_total, currency,
  recipient_name, recipient_phone, full_address, city, state, country, postal_code,
  total_price, total_charges, created_at
)
VALUES
  (
    '00000000-0000-4000-8000-000000000101', '00000000-0000-4000-8000-000000000001', 'created', 'success',
    499.00, 0, 0, 499.00, 'INR',
    'Local Admin', '9109322140', '1 Test Street', 'Bengaluru', 'KA', 'IN', '560001',
    499.00, 0, CURRENT_TIMESTAMP
  ),
  (
    '00000000-0000-4000-8000-000000000102', '00000000-0000-4000-8000-000000000001', 'created', 'pending',
    249.00, 0, 0, 249.00, 'INR',
    'Local Admin', '9109322140', '1 Test Street', 'Bengaluru', 'KA', 'IN', '560001',
    249.00, 0, CURRENT_TIMESTAMP
  );
