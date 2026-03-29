-- Optional: run only if `phone_otp_challenges` still has `purpose`, `full_name`, or `email` columns
-- (older installs). Fresh installs should use `database/schema.sql` only.

ALTER TABLE phone_otp_challenges DROP INDEX idx_phone_otp_phone_purpose;

ALTER TABLE phone_otp_challenges
    DROP COLUMN purpose,
    DROP COLUMN full_name,
    DROP COLUMN email;

DELETE t1 FROM phone_otp_challenges t1
INNER JOIN phone_otp_challenges t2
    ON t1.phone = t2.phone AND t1.created_at < t2.created_at;

ALTER TABLE phone_otp_challenges ADD UNIQUE KEY uq_phone_otp_phone (phone);
