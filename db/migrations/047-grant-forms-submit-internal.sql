-- Grant internal form submission permission to deployment roles

INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'forms.submit_internal' AS permission_key
) perms ON r.slug IN ('deployment_specialist','deployment_specialist_lead','admin');
