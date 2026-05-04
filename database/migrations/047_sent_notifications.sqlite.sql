CREATE TABLE sent_notifications (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    title TEXT NOT NULL,
    subtitle TEXT NULL,
    body TEXT NOT NULL,
    data TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_sent_notifications_user ON sent_notifications (user_id);
CREATE INDEX idx_sent_notifications_created ON sent_notifications (created_at);
