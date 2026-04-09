-- Warehouses: INT AUTO_INCREMENT id (starting at 1000).
-- Since this deployment has no warehouses yet, we can safely recreate the table.

DROP TABLE IF EXISTS warehouses;

CREATE TABLE IF NOT EXISTS warehouses (
    id INT NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    address_line_1 VARCHAR(512) NOT NULL,
    address_line_2 VARCHAR(512) NULL,
    area VARCHAR(255) NULL,
    city VARCHAR(128) NOT NULL,
    state VARCHAR(128) NOT NULL,
    country VARCHAR(128) NOT NULL,
    postal_code VARCHAR(32) NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL DEFAULT 0,
    longitude DECIMAL(10, 7) NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouses_uuid (uuid),
    UNIQUE KEY uq_warehouses_name (name),
    KEY idx_warehouses_status (status),
    KEY idx_warehouses_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1000;

