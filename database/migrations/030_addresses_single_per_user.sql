-- Enforce one address per user.
-- Keep a single best row per user before applying unique constraint:
-- 1) default address preferred
-- 2) most recently created preferred
-- 3) largest id as final tie-breaker

DELETE a1
FROM addresses a1
INNER JOIN addresses a2
    ON a1.user_id = a2.user_id
    AND (
        a1.is_default < a2.is_default
        OR (a1.is_default = a2.is_default AND a1.created_at < a2.created_at)
        OR (a1.is_default = a2.is_default AND a1.created_at = a2.created_at AND a1.id < a2.id)
    );

ALTER TABLE addresses
    DROP INDEX idx_addresses_user,
    ADD UNIQUE KEY uq_addresses_user (user_id);
