-- Order support queries: one support thread per order with message history.

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
