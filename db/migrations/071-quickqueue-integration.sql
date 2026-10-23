-- QuickQueue integration persistence: durable idempotency and ticket links.

ALTER TABLE purchase_orders
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN external_requested_by_email VARCHAR(190) DEFAULT NULL AFTER updated_at,
    ADD COLUMN external_requested_by_name VARCHAR(190) DEFAULT NULL AFTER external_requested_by_email;

CREATE TABLE IF NOT EXISTS external_api_idempotency (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json JSON NOT NULL,
    order_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_external_idempotency_api_key FOREIGN KEY (api_key_id)
        REFERENCES api_keys(id) ON DELETE CASCADE,
    CONSTRAINT fk_external_idempotency_order FOREIGN KEY (order_id)
        REFERENCES purchase_orders(id) ON DELETE SET NULL,
    UNIQUE KEY uq_external_idempotency_key (api_key_id, idempotency_key),
    INDEX idx_external_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_ticket_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    ticket_system VARCHAR(50) NOT NULL,
    ticket_external_id VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_external_ticket_link_order FOREIGN KEY (purchase_order_id)
        REFERENCES purchase_orders(id) ON DELETE CASCADE,
    UNIQUE KEY uq_external_ticket_link_order (purchase_order_id),
    INDEX idx_external_ticket_link_ticket (ticket_system, ticket_external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
