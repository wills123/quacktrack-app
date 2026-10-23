-- Regional supply receipt is separate from order administration and Snipe asset custody.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'receiving.receive_region'
FROM roles r
WHERE r.slug IN ('field_tech', 'field_tech_lead', 'admin');
