-- Order support queries: one support thread per order with message history.

CREATE TABLE IF NOT EXISTS order_support_queries (
    id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    subject VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by CHAR(36) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_support_queries_order (order_id),
    KEY idx_order_support_queries_status (status),
    KEY idx_order_support_queries_user (user_id),
    CONSTRAINT fk_order_support_queries_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_support_queries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_support_queries_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_support_messages (
    id CHAR(36) NOT NULL,
    query_id CHAR(36) NOT NULL,
    sender_user_id CHAR(36) NOT NULL,
    sender_role VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_support_messages_query (query_id, created_at),
    KEY idx_order_support_messages_sender (sender_user_id),
    CONSTRAINT fk_order_support_messages_query FOREIGN KEY (query_id) REFERENCES order_support_queries (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_support_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
