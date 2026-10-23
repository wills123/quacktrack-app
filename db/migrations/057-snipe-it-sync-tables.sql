-- Snipe IT Integration: sync log and asset cache
-- Purpose: Track all Snipe IT API calls for debugging, monitoring, and rate limit awareness
-- Source: Quacktrack migration pattern (see 053-surveys.sql, 055-api-keys.sql)

CREATE TABLE IF NOT EXISTS snipe_it_sync_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL COMMENT 'Snipe IT API endpoint, e.g. /hardware, /categories',
    action VARCHAR(50) NOT NULL DEFAULT 'list' COMMENT 'API action: list, get, post, put, patch, delete',
    status ENUM('success', 'error') NOT NULL DEFAULT 'success' COMMENT 'API call outcome',
    response_time_ms INT UNSIGNED DEFAULT NULL COMMENT 'HTTP response time in milliseconds',
    record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of records returned (for list/search)',
    error_message TEXT DEFAULT NULL COMMENT 'Error details if status=error',
    user_id INT DEFAULT NULL COMMENT 'Quacktrack user who triggered the API call',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this API call was made',

    INDEX idx_sync_endpoint (endpoint),
    INDEX idx_sync_created (created_at),
    INDEX idx_sync_status (status),
    INDEX idx_sync_user (user_id),

    CONSTRAINT fk_sync_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Snipe IT API call audit log for monitoring, debugging, and rate limiting';
