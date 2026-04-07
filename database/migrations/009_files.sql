-- Secure file storage metadata (served via controlled endpoint, not public directory)

CREATE TABLE IF NOT EXISTS files (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'misc', -- brands | variants | misc
    storage_path VARCHAR(512) NOT NULL,       -- relative path under storage dir
    original_name VARCHAR(255) NULL,
    mime VARCHAR(128) NOT NULL,
    size_bytes INT NOT NULL,
    access_key CHAR(64) NOT NULL,             -- random hex
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_files_access_key (access_key),
    KEY idx_files_kind (kind),
    KEY idx_files_created_by (created_by),
    KEY idx_files_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;