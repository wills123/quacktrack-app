-- Kit Templates & Shipments
-- Purpose: Allow service desk to define reusable kit templates and ship them to stores.
-- Each template is a named list of items (Quack Track products or Snipe-IT models).
-- Shipments bulk-checkout assets from Snipe-IT to a destination store in one action.

-- =============================================
-- KIT TEMPLATES
-- =============================================
CREATE TABLE IF NOT EXISTS kit_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_kit_templates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_kit_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_kit_templates_active (is_active),
    INDEX idx_kit_templates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reusable kit definitions used by service desk for new store deployments';

-- =============================================
-- KIT TEMPLATE ITEMS
-- =============================================
CREATE TABLE IF NOT EXISTS kit_template_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kit_template_id INT NOT NULL,

    -- Source: either a Quack Track product OR a Snipe-IT model (not both)
    item_type ENUM('product', 'snipe_model') NOT NULL DEFAULT 'product',
    product_id INT DEFAULT NULL COMMENT 'FK to products table (item_type=product)',
    snipe_model_id INT UNSIGNED DEFAULT NULL COMMENT 'Snipe-IT model ID (item_type=snipe_model)',
    snipe_model_name VARCHAR(255) DEFAULT NULL COMMENT 'Cached Snipe-IT model name for display',

    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    notes VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_kit_items_template FOREIGN KEY (kit_template_id) REFERENCES kit_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_kit_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,

    INDEX idx_kit_items_template (kit_template_id),
    INDEX idx_kit_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Line items within a kit template — either catalog products or Snipe-IT models';

-- =============================================
-- PERMISSIONS — Kit & Shipments group
-- =============================================

-- Grant service_desk full kit access
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
CROSS JOIN (
    SELECT 'kit.view'              AS permission_key UNION ALL
    SELECT 'kit.manage'            UNION ALL
    SELECT 'kit.shipments.view'    UNION ALL
    SELECT 'kit.shipments.create'  UNION ALL
    SELECT 'kit.shipments.manage'
) perms
WHERE r.slug = 'service_desk';

-- Grant admin full kit access
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
CROSS JOIN (
    SELECT 'kit.view'              AS permission_key UNION ALL
    SELECT 'kit.manage'            UNION ALL
    SELECT 'kit.shipments.view'    UNION ALL
    SELECT 'kit.shipments.create'  UNION ALL
    SELECT 'kit.shipments.manage'
) perms
WHERE r.slug = 'admin';

-- Grant deployment specialists view-only access (so they can see what was shipped to their site)
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
CROSS JOIN (
    SELECT 'kit.view'           AS permission_key UNION ALL
    SELECT 'kit.shipments.view'
) perms
WHERE r.slug IN ('deployment_specialist', 'deployment_specialist_lead');
