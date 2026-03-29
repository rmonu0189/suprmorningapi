CREATE TABLE IF NOT EXISTS pages (
    id CHAR(36) NOT NULL,
    card_index INT NOT NULL DEFAULT 0,
    page_name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    content JSON NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pages_page_name (page_name),
    KEY idx_pages_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
