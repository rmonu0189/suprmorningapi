-- Track fulfillment status changes (who changed what, when)
-- Keeps an immutable audit trail per order.

CREATE TABLE IF NOT EXISTS order_status_events (
    id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    status VARCHAR(32) NOT NULL,            -- e.g. packed|out_for_delivery|delivered
    changed_by CHAR(36) NULL,               -- user who performed the action (picker/rider/admin)
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_status_events_order_created (order_id, created_at),
    KEY idx_order_status_events_changed_by (changed_by),
    CONSTRAINT fk_order_status_events_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_status_events_changed_by FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

