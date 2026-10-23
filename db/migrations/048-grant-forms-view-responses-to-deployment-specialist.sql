-- Allow deployment specialists to view form responses (needed for onsite vs contractor comparison)

INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'forms.view_responses' AS permission_key
) perms ON r.slug IN ('deployment_specialist','deployment_specialist_lead','admin');
