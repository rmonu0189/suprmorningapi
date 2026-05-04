CREATE TABLE IF NOT EXISTS files (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by TEXT NULL,
  kind TEXT NOT NULL DEFAULT 'misc',
  storage_path TEXT NOT NULL,
  original_name TEXT NULL,
  mime TEXT NOT NULL,
  size_bytes INTEGER NOT NULL,
  access_key TEXT NOT NULL UNIQUE,
  is_active INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_files_kind ON files(kind);
CREATE INDEX IF NOT EXISTS idx_files_created_by ON files(created_by);
CREATE INDEX IF NOT EXISTS idx_files_active ON files(is_active);

ALTER TABLE orders ADD COLUMN invoice_file_id TEXT NULL;
ALTER TABLE orders ADD COLUMN invoice_number TEXT NULL;
ALTER TABLE orders ADD COLUMN invoice_generated_at TEXT NULL;
ALTER TABLE orders ADD COLUMN invoice_status TEXT NULL;
ALTER TABLE orders ADD COLUMN invoice_error TEXT NULL;
ALTER TABLE orders ADD COLUMN invoice_attempts INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_orders_invoice_file ON orders(invoice_file_id);
CREATE INDEX IF NOT EXISTS idx_orders_invoice_status ON orders(invoice_status);
CREATE UNIQUE INDEX IF NOT EXISTS uq_orders_invoice_number ON orders(invoice_number);
