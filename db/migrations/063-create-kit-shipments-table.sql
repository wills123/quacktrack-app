-- Kit Shipments
-- Purpose: Track when a kit template is dispatched to a store.
-- Each shipment bulk-checks out Snipe-IT assets and records catalog item fulfillments.

CREATE TABLE IF NOT EXISTS kit_shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kit_template_id INT NOT NULL,
    store_id INT NOT NULL,

    status ENUM('pending','dispatched','received','cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,

    -- Snapshot the kit name at dispatch time so history survives template renames
    kit_name_snapshot VARCHAR(150) NOT NULL DEFAULT '',

    dispatched_by INT DEFAULT NULL,
    dispatched_at  TIMESTAMP NULL DEFAULT NULL,
    received_at    TIMESTAMP NULL DEFAULT NULL,

    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_shipments_kit    FOREIGN KEY (kit_template_id) REFERENCES kit_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_shipments_store  FOREIGN KEY (store_id)        REFERENCES stores(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_shipments_dispatcher FOREIGN KEY (dispatched_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_shipments_creator    FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_shipments_kit    (kit_template_id),
    INDEX idx_shipments_store  (store_id),
    INDEX idx_shipments_status (status),
    INDEX idx_shipments_dispatched (dispatched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Records of kit templates dispatched to stores';

-- ─────────────────────────────────────────────────────────────────────────────
-- Kit Shipment Line Items
-- One row per kit item: records what was supposed to ship and what actually did.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kit_shipment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kit_shipment_id INT NOT NULL,

    -- Mirrors the kit_template_items columns (snapshot at dispatch time)
    item_type ENUM('product','snipe_model') NOT NULL,
    product_id INT DEFAULT NULL,
    snipe_model_id INT UNSIGNED DEFAULT NULL,
    snipe_model_name VARCHAR(255) DEFAULT NULL,
    quantity_planned INT UNSIGNED NOT NULL DEFAULT 1,

    -- Snipe-IT checkout result (NULL until dispatched)
    snipe_asset_ids JSON DEFAULT NULL COMMENT 'Array of Snipe-IT asset IDs that were checked out',
    quantity_fulfilled INT UNSIGNED NOT NULL DEFAULT 0,

    -- Outcome status per line
    line_status ENUM('pending','fulfilled','partial','unavailable') NOT NULL DEFAULT 'pending',
    line_notes VARCHAR(500) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_shipment_items_shipment FOREIGN KEY (kit_shipment_id) REFERENCES kit_shipments(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipment_items_product  FOREIGN KEY (product_id)       REFERENCES products(id)      ON DELETE SET NULL,

    INDEX idx_shipment_items_shipment (kit_shipment_id),
    INDEX idx_shipment_items_product  (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Line-level results for each kit shipment — asset IDs checked out per item';
