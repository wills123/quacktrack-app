-- Migration: Backfill user_roles table from legacy users.role column
-- Purpose: Ensure all existing users have proper role assignments in the RBAC system
-- Date: 2025-11-25

-- Step 1: Insert role assignments for all users who don't have one yet
-- This uses the legacy `users.role` column to determine the appropriate RBAC role

INSERT INTO user_roles (user_id, role_id)
SELECT
    u.id AS user_id,
    r.id AS role_id
FROM users u
CROSS JOIN roles r
WHERE
    -- Only insert if user doesn't already have this role
    NOT EXISTS (
        SELECT 1 FROM user_roles ur
        WHERE ur.user_id = u.id AND ur.role_id = r.id
    )
    -- Map legacy role to RBAC role
    AND (
        (LOWER(u.role) = 'admin' AND r.slug = 'admin')
        OR (LOWER(u.role) = 'manager' AND r.slug = 'field_tech_lead')
        OR (LOWER(u.role) = 'user' AND r.slug = 'field_tech')
        OR (u.role IS NULL AND r.slug = 'field_tech') -- Default null roles to field_tech
        OR (LOWER(u.role) NOT IN ('admin', 'manager', 'user') AND r.slug = 'field_tech') -- Default unknown roles to field_tech
    );

-- Step 2: Log the migration results to audit_logs table (if it exists)
-- This creates an audit trail of all role assignments created by this migration

INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, metadata, ip_address, user_agent, request_uri, request_method)
SELECT
    ur.user_id,
    'role_assignment_backfill',
    'user_role',
    ur.id,
    'Assigned role via legacy backfill',
    JSON_OBJECT(
        'role_id', ur.role_id,
        'role_name', r.name,
        'role_slug', r.slug,
        'user_email', u.email,
        'legacy_role', u.role,
        'migration', '24-backfill_user_roles',
        'created_by', 'system_migration'
    ),
    '127.0.0.1',
    'Migration Script 24',
    '/db/migrations/24-backfill_user_roles.sql',
    'MIGRATE'
FROM user_roles ur
JOIN users u ON ur.user_id = u.id
JOIN roles r ON ur.role_id = r.id
WHERE ur.created_at >= (NOW() - INTERVAL 1 MINUTE)
  AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_logs');

-- Step 3: Verify all users have at least one role
-- This select will show any users who still don't have a role (should be empty)

SELECT
    u.id,
    u.email,
    u.role AS legacy_role,
    GROUP_CONCAT(r.name SEPARATOR ', ') AS assigned_roles,
    CASE
        WHEN COUNT(ur.id) = 0 THEN 'MISSING ROLE'
        ELSE 'OK'
    END AS status
FROM users u
LEFT JOIN user_roles ur ON u.id = ur.user_id
LEFT JOIN roles r ON ur.role_id = r.id
GROUP BY u.id, u.email, u.role
ORDER BY status DESC, u.email;

-- Expected output: All users should have status = 'OK'
-- If any user shows 'MISSING ROLE', the migration needs investigation
