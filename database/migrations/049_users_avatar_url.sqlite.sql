-- Add avatar_url column to users table (SQLite)
ALTER TABLE users ADD COLUMN avatar_url TEXT DEFAULT NULL;
