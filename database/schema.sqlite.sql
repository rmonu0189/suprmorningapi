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

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  phone TEXT NOT NULL,
  country_code TEXT NOT NULL DEFAULT '+91',
  email TEXT NULL UNIQUE,
  full_name TEXT NULL,
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

CREATE TABLE IF NOT EXISTS orders (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  order_status TEXT NOT NULL DEFAULT 'created',
  payment_status TEXT NOT NULL DEFAULT 'pending',
  grand_total REAL NOT NULL DEFAULT 0,
  currency TEXT NOT NULL DEFAULT 'INR',
  order_kind TEXT NOT NULL DEFAULT 'user',
  stock_deducted_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

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

-- Seed an admin user for local OTP login (OTP_TEST_PHONES includes 919109322140).
INSERT OR IGNORE INTO users (id, phone, country_code, email, full_name, is_active, role)
VALUES ('00000000-0000-4000-8000-000000000001', '9109322140', '+91', 'admin@local.test', 'Local Admin', 1, 'admin');

-- Seed a couple of orders for the dashboard recent orders table.
INSERT OR IGNORE INTO orders (id, user_id, order_status, payment_status, grand_total, currency, created_at)
VALUES
  ('00000000-0000-4000-8000-000000000101', '00000000-0000-4000-8000-000000000001', 'created', 'success', 499.00, 'INR', CURRENT_TIMESTAMP),
  ('00000000-0000-4000-8000-000000000102', '00000000-0000-4000-8000-000000000001', 'created', 'pending', 249.00, 'INR', CURRENT_TIMESTAMP);

