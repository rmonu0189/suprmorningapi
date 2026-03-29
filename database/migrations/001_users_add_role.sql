-- Optional: add role to existing `users` table.

ALTER TABLE users
    ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT 'user' AFTER is_active;
