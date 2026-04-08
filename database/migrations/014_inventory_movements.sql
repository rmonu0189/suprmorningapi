-- Inventory movements/history.
-- Records each stock adjustment with timestamp for audit + reporting.

CREATE TABLE IF NOT EXISTS inventory_movements (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    variant_id CHAR(36) NOT NULL,
    delta_quantity INT NOT NULL,
    note TEXT NULL,
    created_by CHAR(36) NULL,
    PRIMARY KEY (id),
    KEY idx_inventory_movements_variant (variant_id),
    KEY idx_inventory_movements_created (created_at),
    CONSTRAINT fk_inventory_movements_variant FOREIGN KEY (variant_id) REFERENCES variants (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_movements_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

