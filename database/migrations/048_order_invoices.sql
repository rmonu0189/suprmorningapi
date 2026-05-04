ALTER TABLE orders
    ADD COLUMN invoice_file_id CHAR(36) NULL,
    ADD COLUMN invoice_number VARCHAR(64) NULL,
    ADD COLUMN invoice_generated_at DATETIME NULL,
    ADD COLUMN invoice_status VARCHAR(32) NULL,
    ADD COLUMN invoice_error TEXT NULL,
    ADD COLUMN invoice_attempts INT NOT NULL DEFAULT 0,
    ADD KEY idx_orders_invoice_file (invoice_file_id),
    ADD KEY idx_orders_invoice_status (invoice_status),
    ADD UNIQUE KEY uq_orders_invoice_number (invoice_number),
    ADD CONSTRAINT fk_orders_invoice_file FOREIGN KEY (invoice_file_id) REFERENCES files (id) ON DELETE SET NULL;
