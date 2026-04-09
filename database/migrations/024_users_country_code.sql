-- Users: store phone country code separately (default +91).
-- Phone login/registration uses `phone` without country code.

ALTER TABLE users
    ADD COLUMN country_code VARCHAR(8) NOT NULL DEFAULT '+91' AFTER id;

-- Replace old unique key on phone with a composite key.
ALTER TABLE users
    DROP INDEX uq_users_phone,
    ADD UNIQUE KEY uq_users_country_phone (country_code, phone);

