-- Allow status value skipped_insufficient_wallet (28 chars); column was VARCHAR(24).

ALTER TABLE subscription_order_generation
    MODIFY status VARCHAR(40) NOT NULL DEFAULT 'pending';
