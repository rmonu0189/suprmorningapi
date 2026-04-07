-- Raw payment gateway webhook event log (Razorpay, etc.)
-- Stores all received payment events for audit/debugging.

CREATE TABLE IF NOT EXISTS payment_events (
    id CHAR(36) NOT NULL,
    gateway VARCHAR(32) NOT NULL DEFAULT 'razorpay',
    event VARCHAR(128) NOT NULL,
    gateway_order_id VARCHAR(255) NULL,
    payload LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_events_gateway_order (gateway_order_id),
    KEY idx_payment_events_event (event),
    KEY idx_payment_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;