-- API Keys table for external integrations (Power BI, etc.)
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    key_hash VARCHAR(64) NOT NULL,
    key_prefix VARCHAR(8) NOT NULL,
    scopes VARCHAR(500) NOT NULL DEFAULT 'powerbi:read',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    last_used_ip VARCHAR(45) DEFAULT NULL,
    request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    created_by_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_api_keys_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_api_key_hash (key_hash),
    INDEX idx_api_keys_active (is_active),
    INDEX idx_api_keys_prefix (key_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
