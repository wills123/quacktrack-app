-- Public share links for contractor submissions (token-based, not tied to a specific recipient)
-- Store only SHA-256 hashes of tokens.

CREATE TABLE IF NOT EXISTS form_share_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_form_share_links_campaign FOREIGN KEY (campaign_id) REFERENCES form_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_share_links_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY uk_form_share_links_token_hash (token_hash),
    INDEX idx_form_share_links_campaign (campaign_id),
    INDEX idx_form_share_links_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
