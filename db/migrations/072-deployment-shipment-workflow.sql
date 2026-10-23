-- Mobile-first deployment shipment requests and lifecycle tracking.

ALTER TABLE kit_shipments
    MODIFY COLUMN store_id INT DEFAULT NULL,
    MODIFY COLUMN status ENUM(
        'pending',
        'preparing',
        'dispatching',
        'dispatched',
        'partially_received',
        'received',
        'declined',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN deployment_id INT DEFAULT NULL AFTER store_id,
    ADD COLUMN deployment_quest_id INT DEFAULT NULL AFTER deployment_id,
    ADD COLUMN approval_status ENUM('not_required','pending','approved','bypassed','declined')
        NOT NULL DEFAULT 'not_required' AFTER status,
    ADD COLUMN request_key CHAR(36) DEFAULT NULL AFTER approval_status,
    ADD COLUMN requested_by INT DEFAULT NULL AFTER request_key,
    ADD COLUMN requested_at TIMESTAMP NULL DEFAULT NULL AFTER requested_by,
    ADD COLUMN needed_by DATE DEFAULT NULL AFTER requested_at,
    ADD COLUMN request_notes TEXT DEFAULT NULL AFTER needed_by,
    ADD COLUMN approval_reason VARCHAR(500) DEFAULT NULL AFTER request_notes,
    ADD COLUMN approved_by INT DEFAULT NULL AFTER approval_reason,
    ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by,
    ADD COLUMN destination_name VARCHAR(190) DEFAULT NULL AFTER approved_at,
    ADD COLUMN destination_address TEXT DEFAULT NULL AFTER destination_name,
    ADD COLUMN carrier VARCHAR(100) DEFAULT NULL AFTER destination_address,
    ADD COLUMN tracking_number VARCHAR(190) DEFAULT NULL AFTER carrier,
    ADD CONSTRAINT fk_shipments_deployment
        FOREIGN KEY (deployment_id) REFERENCES deployment_projects(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_shipments_deployment_quest
        FOREIGN KEY (deployment_quest_id) REFERENCES deployment_quests(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_shipments_requested_by
        FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_shipments_approved_by
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD UNIQUE KEY uk_shipments_request_key (request_key),
    ADD INDEX idx_shipments_deployment (deployment_id),
    ADD INDEX idx_shipments_deployment_quest (deployment_quest_id),
    ADD INDEX idx_shipments_approval_status (approval_status),
    ADD INDEX idx_shipments_needed_by (needed_by);

ALTER TABLE kit_shipment_items
    ADD COLUMN quantity_received INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity_fulfilled,
    ADD COLUMN received_notes VARCHAR(500) DEFAULT NULL AFTER quantity_received;

CREATE TABLE IF NOT EXISTS kit_shipment_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    kit_shipment_id INT NOT NULL,
    actor_user_id INT DEFAULT NULL,
    event_type VARCHAR(50) NOT NULL,
    message VARCHAR(750) NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shipment_events_shipment
        FOREIGN KEY (kit_shipment_id) REFERENCES kit_shipments(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipment_events_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_shipment_events_shipment (kit_shipment_id, created_at),
    INDEX idx_shipment_events_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User-facing deployment shipment lifecycle history';

-- Existing shipment records predate requests and approvals. Keep them as
-- historical direct shipments and mark their shipped quantities as received
-- only when the shipment itself was already received.
UPDATE kit_shipment_items ksi
JOIN kit_shipments ks ON ks.id = ksi.kit_shipment_id
SET ksi.quantity_received = ksi.quantity_fulfilled
WHERE ks.status = 'received';

INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, permissions.permission_key
FROM roles r
CROSS JOIN (
    SELECT 'deployment.shipments.request' AS permission_key UNION ALL
    SELECT 'deployment.shipments.receive'
) permissions
WHERE r.slug IN ('deployment_specialist', 'deployment_specialist_lead', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, 'deployment.shipments.approve'
FROM roles r
WHERE r.slug IN ('deployment_specialist_lead', 'service_desk', 'admin');
