-- SQLite companion for 038_wallet_holds.sql

ALTER TABLE wallets ADD COLUMN locked_balance REAL NOT NULL DEFAULT 0;

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
