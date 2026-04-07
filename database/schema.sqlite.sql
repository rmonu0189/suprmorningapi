-- SQLite schema for local development (minimal subset).
-- This is only used when DB_CONNECTION=sqlite.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  phone TEXT NOT NULL UNIQUE,
  email TEXT NULL UNIQUE,
  full_name TEXT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  role TEXT NOT NULL DEFAULT 'user',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

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

-- Minimal catalog tables for analytics overview.
CREATE TABLE IF NOT EXISTS products (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS variants (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  order_status TEXT NOT NULL DEFAULT 'created',
  payment_status TEXT NOT NULL DEFAULT 'pending',
  grand_total REAL NOT NULL DEFAULT 0,
  currency TEXT NOT NULL DEFAULT 'INR',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed an admin user for local OTP login (OTP_TEST_PHONES includes 919109322140).
INSERT OR IGNORE INTO users (id, phone, email, full_name, is_active, role)
VALUES ('00000000-0000-4000-8000-000000000001', '919109322140', 'admin@local.test', 'Local Admin', 1, 'admin');

-- Seed a couple of orders for the dashboard recent orders table.
INSERT OR IGNORE INTO orders (id, user_id, order_status, payment_status, grand_total, currency, created_at)
VALUES
  ('00000000-0000-4000-8000-000000000101', '00000000-0000-4000-8000-000000000001', 'created', 'success', 499.00, 'INR', CURRENT_TIMESTAMP),
  ('00000000-0000-4000-8000-000000000102', '00000000-0000-4000-8000-000000000001', 'created', 'pending', 249.00, 'INR', CURRENT_TIMESTAMP);

