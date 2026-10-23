-- Snipe IT Integration: Create service_desk role and asset permissions
-- Purpose: Define role-based access control for asset management features
-- Source: Quacktrack RBAC pattern (see 023-roles.sql, 054-permissions.sql)

-- Step 1: Create service_desk role (does not exist in migrations 001-056)
INSERT INTO roles (name, slug, description, is_protected)
VALUES ('Service Desk', 'service_desk', 'Service desk staff with IT asset management capabilities', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Step 2: Define all asset-related permissions
-- These will be referenced by the app but need to exist for the UI admin panel

-- Asset viewing: all roles can view assets
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'assets.view'
FROM roles r
WHERE r.slug IN ('field_tech', 'field_tech_lead', 'service_desk', 'admin');

-- Asset checkout/checkin: all roles can checkout and checkin
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
CROSS JOIN (
    SELECT 'assets.checkout' AS permission_key UNION ALL
    SELECT 'assets.checkin'
) perms
WHERE r.slug IN ('field_tech', 'field_tech_lead', 'service_desk', 'admin');

-- Asset management: service_desk and admin only
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
CROSS JOIN (
    SELECT 'assets.manage' AS permission_key UNION ALL
    SELECT 'assets.licenses' UNION ALL
    SELECT 'assets.maintenance' UNION ALL
    SELECT 'assets.categories' UNION ALL
    SELECT 'assets.reports'
) perms
WHERE r.slug IN ('service_desk', 'admin');

-- Admin-only asset administration
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'assets.admin'
FROM roles r WHERE r.slug = 'admin';
