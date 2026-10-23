-- Acumatica ERP Integration: permissions
-- Purpose: Define role-based access to the Acumatica integration.
-- Viewing live stock/tracking is low-risk and broadly useful; creating a PO
-- (even as a Hold/draft) is restricted the same way kit-shipment writes are.
-- Source: Quacktrack RBAC pattern (see 058-snipe-it-permissions.sql)

-- Inventory/tracking lookups: any role that can already work with kits or deployments
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'acumatica.inventory.view'
FROM roles r
WHERE r.slug IN ('field_tech', 'field_tech_lead', 'service_desk', 'admin');

-- Creating a (Hold/draft) PO: same roles trusted with kit-shipment/deployment writes
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'acumatica.po.create'
FROM roles r
WHERE r.slug IN ('field_tech_lead', 'service_desk', 'admin');

-- Admin-only: manage the product<->Acumatica InventoryID/vendor mapping
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'acumatica.admin'
FROM roles r WHERE r.slug = 'admin';
