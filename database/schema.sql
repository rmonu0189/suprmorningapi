-- SuprMorning API — full schema (MySQL 8+ / InnoDB, utf8mb4).
-- From project root: mysql -u USER -p DB_NAME < database/schema.sql
--
-- Layout:
--   schema.sql     — baseline tables (run on fresh databases)
--   migrations/    — incremental SQL for existing deployments (add files as needed)
--
-- Catalog: brands → products → variants → inventory (mirrors Supabase product tables in MySQL).

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NULL,
    full_name VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    role VARCHAR(50) NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phone_otp_challenges (
    id CHAR(36) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    salt_hex CHAR(32) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_phone_otp_phone (phone),
    KEY idx_phone_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    user_agent VARCHAR(512) NULL,
    device_label VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    KEY idx_refresh_tokens_user (user_id),
    KEY idx_refresh_tokens_expires (expires_at),
    CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ---------------------------------------------------------------------------
-- Product catalog (Supabase-style: brands, products, variants, inventory)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS brands (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    name VARCHAR(255) NOT NULL,
    about TEXT NULL,
    logo TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_brands_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS products (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    brand_id CHAR(36) NOT NULL,
    category_id CHAR(36) NULL,
    subcategory_id CHAR(36) NULL,
    name VARCHAR(512) NOT NULL,
    description TEXT NULL,
    tags JSON NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    metadata JSON NULL,
    PRIMARY KEY (id),
    KEY idx_products_brand (brand_id),
    KEY idx_products_category_id (category_id),
    KEY idx_products_subcategory_id (subcategory_id),
    KEY idx_products_status (status),
    CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_products_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variants (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    product_id CHAR(36) NOT NULL,
    name TEXT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    mrp DECIMAL(12, 2) NOT NULL,
    images JSON NULL,
    metadata JSON NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    discount_tag VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variants_sku (sku),
    KEY idx_variants_product (product_id),
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    variant_id CHAR(36) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    reserved_quantity SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_variant (variant_id),
    CONSTRAINT fk_inventory_variant FOREIGN KEY (variant_id) REFERENCES variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Commerce (mobile app)

CREATE TABLE IF NOT EXISTS carts (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    coupon_code VARCHAR(128) NULL,
    discount_type VARCHAR(64) NULL,
    discount_value VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_carts_user_status (user_id, status),
    CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
    id CHAR(36) NOT NULL,
    cart_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    variant_id CHAR(36) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12, 2) NOT NULL,
    unit_mrp DECIMAL(12, 2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_items_cart_variant (cart_id, variant_id),
    KEY idx_cart_items_user (user_id),
    KEY idx_cart_items_variant (variant_id),
    CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES variants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_charges (
    id CHAR(36) NOT NULL,
    charge_index INT NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    min_order_value DECIMAL(12, 2) NULL,
    info TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_cart_charges_index (charge_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Coupons (admin-managed)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS coupons (
    id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    code VARCHAR(128) NOT NULL,
    discount_type VARCHAR(32) NOT NULL, -- 'fixed' | 'percentage'
    discount_value DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    min_cart_value DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    starts_at DATE NOT NULL,
    expires_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    max_discount DECIMAL(12, 2) NULL,
    usage_limit INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupons_code (code),
    KEY idx_coupons_active (is_active),
    KEY idx_coupons_starts (starts_at),
    KEY idx_coupons_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Secure file storage metadata (served via API, not public directory)
-- ---------------------------------------------------------------------------

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

CREATE TABLE IF NOT EXISTS addresses (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    label VARCHAR(64) NOT NULL,
    recipient_name VARCHAR(255) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    address_line_1 VARCHAR(512) NOT NULL,
    address_line_2 VARCHAR(512) NULL,
    area VARCHAR(255) NULL,
    city VARCHAR(128) NOT NULL,
    state VARCHAR(128) NOT NULL,
    country VARCHAR(128) NOT NULL,
    postal_code VARCHAR(32) NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL DEFAULT 0,
    longitude DECIMAL(10, 7) NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addresses_user (user_id),
    CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loves (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    variant_id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loves_user_variant (user_id, variant_id),
    KEY idx_loves_variant (variant_id),
    CONSTRAINT fk_loves_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_loves_variant FOREIGN KEY (variant_id) REFERENCES variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    cart_id CHAR(36) NULL,
    address_id CHAR(36) NULL,
    order_status VARCHAR(32) NOT NULL DEFAULT 'created',
    payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    delivery_date DATE NULL,
    delivery_slot VARCHAR(64) NULL,
    delivery_type VARCHAR(64) NULL,
    total_mrp DECIMAL(12, 2) NOT NULL,
    tax DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(12, 2) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'INR',
    recipient_name VARCHAR(255) NOT NULL,
    recipient_phone VARCHAR(32) NOT NULL,
    full_address TEXT NOT NULL,
    city VARCHAR(128) NOT NULL,
    state VARCHAR(128) NOT NULL,
    country VARCHAR(128) NOT NULL,
    postal_code VARCHAR(32) NOT NULL,
    total_price DECIMAL(12, 2) NOT NULL,
    total_charges DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    gateway_order_id VARCHAR(255) NULL,
    gateway_name VARCHAR(64) NULL DEFAULT 'razorpay',
    charges_metadata JSON NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_orders_user_created (user_id, created_at),
    KEY idx_orders_gateway (gateway_order_id),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_address FOREIGN KEY (address_id) REFERENCES addresses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    image VARCHAR(1024) NOT NULL DEFAULT '',
    brand_name VARCHAR(255) NOT NULL,
    product_name VARCHAR(512) NOT NULL,
    variant_name TEXT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12, 2) NOT NULL,
    unit_mrp DECIMAL(12, 2) NOT NULL,
    sku VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    gateway VARCHAR(32) NOT NULL,
    gateway_order_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'INR',
    status VARCHAR(32) NOT NULL DEFAULT 'initiated',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payments_order (order_id),
    KEY idx_payments_gateway_order (gateway_order_id),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cart_charges (id, charge_index, title, amount, min_order_value, info)
VALUES ('00000000-0000-4000-8000-000000000001', 0, 'Delivery', 40.00, NULL, NULL);
