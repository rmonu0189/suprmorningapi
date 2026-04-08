-- Delivery: per-order-item picking checklist (admin)

CREATE TABLE IF NOT EXISTS delivery_item_checks (
    id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    order_item_id CHAR(36) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending|picked|short|missing|not_available
    picked_quantity INT NOT NULL DEFAULT 0,
    note TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_item_checks_order_item (order_item_id),
    KEY idx_delivery_item_checks_order (order_id),
    CONSTRAINT fk_delivery_item_checks_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_item_checks_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

