-- Per-API-key, per-minute rate limiting buckets for stateless API-key endpoints (e.g. Power BI)
CREATE TABLE IF NOT EXISTS api_key_rate_limits (
    key_id INT NOT NULL,
    minute_bucket INT NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (key_id, minute_bucket),
    CONSTRAINT fk_api_key_rate_limits_key FOREIGN KEY (key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
    INDEX idx_api_key_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
