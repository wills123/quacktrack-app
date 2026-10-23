-- Grant survey permissions to built-in roles
-- Permission keys: surveys.submit, surveys.view_own, surveys.view_all,
--                  surveys.templates.manage, surveys.compare

-- field_tech: can submit and view own surveys
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'surveys.submit' AS permission_key UNION ALL
    SELECT 'surveys.view_own'
) perms ON r.slug = 'field_tech';

-- field_tech_lead: full survey access (submit, view all, manage templates, compare)
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'surveys.submit' AS permission_key UNION ALL
    SELECT 'surveys.view_own' UNION ALL
    SELECT 'surveys.view_all' UNION ALL
    SELECT 'surveys.templates.manage' UNION ALL
    SELECT 'surveys.compare'
) perms ON r.slug = 'field_tech_lead';

-- admin: full survey access
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'surveys.submit' AS permission_key UNION ALL
    SELECT 'surveys.view_own' UNION ALL
    SELECT 'surveys.view_all' UNION ALL
    SELECT 'surveys.templates.manage' UNION ALL
    SELECT 'surveys.compare'
) perms ON r.slug = 'admin';
