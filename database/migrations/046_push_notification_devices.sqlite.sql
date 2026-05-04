CREATE TABLE IF NOT EXISTS push_notification_devices (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  provider TEXT NOT NULL DEFAULT 'expo',
  token TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  platform TEXT NULL,
  device_id TEXT NULL,
  device_name TEXT NULL,
  app_version TEXT NULL,
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_push_notification_devices_user ON push_notification_devices(user_id);
CREATE INDEX IF NOT EXISTS idx_push_notification_devices_provider ON push_notification_devices(provider);
