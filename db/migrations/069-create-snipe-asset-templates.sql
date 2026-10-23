-- Reusable Snipe-IT creation defaults for tracked products.

CREATE TABLE IF NOT EXISTS snipe_asset_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    template_name VARCHAR(120) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    status_id INT UNSIGNED NOT NULL,
    asset_name_template VARCHAR(255) NOT NULL DEFAULT '{{product_name}} {{serial}}',
    asset_tag_prefix VARCHAR(100) DEFAULT NULL,
    default_notes VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    updated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_snipe_asset_template_product UNIQUE (product_id),
    CONSTRAINT fk_snipe_asset_template_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_snipe_asset_template_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_snipe_asset_template_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_snipe_asset_template_active (is_active),
    INDEX idx_snipe_asset_template_model (model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Reusable Snipe-IT creation defaults for tracked products';
