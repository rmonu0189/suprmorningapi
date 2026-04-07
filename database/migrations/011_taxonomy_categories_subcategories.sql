-- Categories & subcategories taxonomy (2-level).
-- Adds curated options + product foreign keys.

CREATE TABLE IF NOT EXISTS categories (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_status (status),
    KEY idx_categories_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subcategories (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    category_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subcategories_category_name (category_id, name),
    UNIQUE KEY uq_subcategories_category_slug (category_id, slug),
    KEY idx_subcategories_category (category_id),
    KEY idx_subcategories_status (status),
    KEY idx_subcategories_sort (sort_order),
    CONSTRAINT fk_subcategories_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
  ADD COLUMN category_id CHAR(36) NULL AFTER brand_id,
  ADD COLUMN subcategory_id CHAR(36) NULL AFTER category_id,
  ADD KEY idx_products_category_id (category_id),
  ADD KEY idx_products_subcategory_id (subcategory_id),
  ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_products_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories (id) ON DELETE SET NULL;

