-- Deterministic order_code sequence (AAAA-000001, AAAA-000002, ...)

CREATE TABLE IF NOT EXISTS order_code_sequence (
    id TINYINT NOT NULL,
    last_number BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure single row exists.
INSERT IGNORE INTO order_code_sequence (id, last_number) VALUES (1, 0);

