-- Migration: Grant product_metadata.manage permission to Admin role
-- This allows admins to manage product categories and manufacturers

-- Grant permission to Admin role
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT id, 'product_metadata.manage'
FROM roles WHERE name = 'Admin';
