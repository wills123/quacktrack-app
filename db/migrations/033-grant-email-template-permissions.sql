-- Migration: Grant email_templates.manage permission to Admin role
-- This allows admins to manage purchasing email templates

-- Grant permission to Admin role
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT id, 'email_templates.manage'
FROM roles WHERE name = 'Admin';
