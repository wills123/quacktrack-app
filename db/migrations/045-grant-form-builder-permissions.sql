-- Grant form builder permissions to relevant roles

-- Deployment Specialist Lead: can manage campaigns and view/export
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'forms.campaigns.manage' AS permission_key UNION ALL
    SELECT 'forms.view_responses' UNION ALL
    SELECT 'forms.export'
) perms ON r.slug = 'deployment_specialist_lead';

-- Admin: full access to form builder features
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'forms.manage' AS permission_key UNION ALL
    SELECT 'forms.campaigns.manage' UNION ALL
    SELECT 'forms.invites.send' UNION ALL
    SELECT 'forms.view_responses' UNION ALL
    SELECT 'forms.export'
) perms ON r.slug = 'admin';
