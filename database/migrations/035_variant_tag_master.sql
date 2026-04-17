-- Master table for canonical variant tags used by admin suggestions and validation.
CREATE TABLE IF NOT EXISTS variant_tag_master (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    name VARCHAR(64) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variant_tag_master_name (name),
    KEY idx_variant_tag_master_status_sort (status, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

