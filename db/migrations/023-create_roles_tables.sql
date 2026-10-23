-- Migration: establish roles and permissions tables

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    is_protected TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_key VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_permission (role_id, permission_key),
    INDEX idx_permission_key (permission_key),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_role (user_id, role_id),
    INDEX idx_role_user (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name, slug, description, is_protected)
VALUES
    ('Admin', 'admin', 'Full administrative access', 1),
    ('Field-Tech Lead', 'field_tech_lead', 'Supervises technicians and manages regions', 1),
    ('Field-Tech', 'field_tech', 'Technician daily workflow', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Field-Tech base permissions
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'dashboard.view' AS permission_key UNION ALL
    SELECT 'cart.manage' UNION ALL
    SELECT 'favorites.manage' UNION ALL
    SELECT 'order_templates.manage' UNION ALL
    SELECT 'orders.create' UNION ALL
    SELECT 'orders.view_own' UNION ALL
    SELECT 'stock.view_own' UNION ALL
    SELECT 'stock.adjust' UNION ALL
    SELECT 'region_storage.view' UNION ALL
    SELECT 'region_storage.adjust' UNION ALL
    SELECT 'region_storage.transfer_van' UNION ALL
    SELECT 'van_stock.view' UNION ALL
    SELECT 'van_stock.adjust' UNION ALL
    SELECT 'products.view'
) perms ON r.slug = 'field_tech';

-- Field-Tech Lead inherits technician + supervisory permissions
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'dashboard.view' AS permission_key UNION ALL
    SELECT 'cart.manage' UNION ALL
    SELECT 'favorites.manage' UNION ALL
    SELECT 'order_templates.manage' UNION ALL
    SELECT 'orders.create' UNION ALL
    SELECT 'orders.view_own' UNION ALL
    SELECT 'orders.view_region' UNION ALL
    SELECT 'orders.view_all' UNION ALL
    SELECT 'stock.view_own' UNION ALL
    SELECT 'stock.adjust' UNION ALL
    SELECT 'region_storage.view' UNION ALL
    SELECT 'region_storage.adjust' UNION ALL
    SELECT 'region_storage.transfer_van' UNION ALL
    SELECT 'region_storage.transfer_region' UNION ALL
    SELECT 'van_stock.view' UNION ALL
    SELECT 'van_stock.adjust' UNION ALL
    SELECT 'van_stock.transactions.view' UNION ALL
    SELECT 'reports.view' UNION ALL
    SELECT 'products.view' UNION ALL
    SELECT 'settings.view'
) perms ON r.slug = 'field_tech_lead';

-- Admin role full permissions
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'system.full_access' AS permission_key UNION ALL
    SELECT 'admin.access' UNION ALL
    SELECT 'dashboard.view' UNION ALL
    SELECT 'cart.manage' UNION ALL
    SELECT 'favorites.manage' UNION ALL
    SELECT 'order_templates.manage' UNION ALL
    SELECT 'orders.create' UNION ALL
    SELECT 'orders.view_own' UNION ALL
    SELECT 'orders.view_region' UNION ALL
    SELECT 'orders.view_all' UNION ALL
    SELECT 'orders.manage' UNION ALL
    SELECT 'region_storage.view' UNION ALL
    SELECT 'region_storage.adjust' UNION ALL
    SELECT 'region_storage.transfer_van' UNION ALL
    SELECT 'region_storage.transfer_region' UNION ALL
    SELECT 'region_storage.audit.view' UNION ALL
    SELECT 'stock.view_own' UNION ALL
    SELECT 'stock.adjust' UNION ALL
    SELECT 'stock.transfer' UNION ALL
    SELECT 'van_stock.view' UNION ALL
    SELECT 'van_stock.adjust' UNION ALL
    SELECT 'van_stock.transfer' UNION ALL
    SELECT 'van_stock.transactions.view' UNION ALL
    SELECT 'products.view' UNION ALL
    SELECT 'products.create' UNION ALL
    SELECT 'products.edit' UNION ALL
    SELECT 'products.delete' UNION ALL
    SELECT 'products.bulk_import' UNION ALL
    SELECT 'admin.users.view' UNION ALL
    SELECT 'admin.users.manage' UNION ALL
    SELECT 'admin.stores.view' UNION ALL
    SELECT 'admin.stores.manage' UNION ALL
    SELECT 'admin.regions.view' UNION ALL
    SELECT 'admin.regions.manage' UNION ALL
    SELECT 'admin.logs.view' UNION ALL
    SELECT 'admin.logs.clear' UNION ALL
    SELECT 'admin.files.manage' UNION ALL
    SELECT 'admin.sessions.manage' UNION ALL
    SELECT 'settings.view' UNION ALL
    SELECT 'settings.manage' UNION ALL
    SELECT 'roles.manage' UNION ALL
    SELECT 'audit_logs.view' UNION ALL
    SELECT 'reports.view' UNION ALL
    SELECT 'api_keys.manage' UNION ALL
    SELECT 'migrations.run'
) perms ON r.slug = 'admin';

-- Legacy user backfill: map existing users.role values into user_roles
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.slug = (
    CASE LOWER(COALESCE(u.role, 'user'))
        WHEN 'admin' THEN 'admin'
        WHEN 'manager' THEN 'field_tech_lead'
        ELSE 'field_tech'
    END
)
ON DUPLICATE KEY UPDATE user_id = user_roles.user_id;
