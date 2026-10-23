-- Acumatica ERP Integration: sync log, PO tracking, and inventory mapping
-- Purpose: Generic, reusable Acumatica API integration (not kit-specific) so any
-- feature can look up live stock levels, shipment tracking, and create purchase
-- orders. Kits is the first consumer; see AcumaticaClient.php.
-- Source: Quacktrack integration pattern (see 057-snipe-it-sync-tables.sql)

-- Map Quack Track products to Acumatica StockItem InventoryIDs, and optionally
-- a default vendor to pre-fill when creating a PO for that item.
ALTER TABLE products
    ADD COLUMN acumatica_inventory_id VARCHAR(30) NULL
        COMMENT 'Acumatica StockItem.InventoryID this product maps to' AFTER purchase_url,
    ADD COLUMN acumatica_default_vendor_id VARCHAR(15) NULL
        COMMENT 'Acumatica Vendor.VendorID to pre-fill when ordering this item' AFTER acumatica_inventory_id;

CREATE TABLE IF NOT EXISTS acumatica_sync_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL COMMENT 'Acumatica REST endpoint, e.g. /StockItem, /PurchaseOrder',
    action VARCHAR(50) NOT NULL DEFAULT 'get' COMMENT 'API action: login, get, post, put, delete',
    status ENUM('success', 'error') NOT NULL DEFAULT 'success' COMMENT 'API call outcome',
    response_time_ms INT UNSIGNED DEFAULT NULL COMMENT 'HTTP response time in milliseconds',
    record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of records returned (for list/search)',
    error_message TEXT DEFAULT NULL COMMENT 'Error details if status=error',
    user_id INT DEFAULT NULL COMMENT 'Quacktrack user who triggered the API call',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this API call was made',

    INDEX idx_acumatica_sync_endpoint (endpoint),
    INDEX idx_acumatica_sync_created (created_at),
    INDEX idx_acumatica_sync_status (status),
    INDEX idx_acumatica_sync_user (user_id),

    CONSTRAINT fk_acumatica_sync_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Acumatica API call audit log for monitoring and debugging';

-- Tracks every PO Quack Track has asked Acumatica to create, regardless of which
-- feature requested it (kits, van restock, etc. via source_type/source_id).
-- Every PO created through this table is a Hold/draft PO in Acumatica -- Quack
-- Track never releases a PO itself. A human always reviews and releases it in
-- Acumatica; this table exists so the requester can see what happened.
CREATE TABLE IF NOT EXISTS acumatica_purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    acumatica_order_nbr VARCHAR(20) NOT NULL COMMENT 'Acumatica PurchaseOrder.OrderNbr',
    acumatica_vendor_id VARCHAR(15) NOT NULL COMMENT 'Acumatica Vendor.VendorID the PO was placed with',
    status VARCHAR(30) NOT NULL DEFAULT 'Hold' COMMENT 'Last known Acumatica PO status (Hold, Open, Completed, etc.)',
    source_type VARCHAR(50) NOT NULL COMMENT 'Feature that requested this PO, e.g. kit_shipment',
    source_id INT NULL COMMENT 'ID within source_type''s own table (e.g. kit_shipments.id)',
    requested_by INT NOT NULL COMMENT 'Quacktrack user who requested the PO',
    line_summary TEXT NULL COMMENT 'JSON snapshot of the lines requested, for audit/history',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_synced_at TIMESTAMP NULL COMMENT 'Last time status was refreshed from Acumatica',

    INDEX idx_acumatica_po_order_nbr (acumatica_order_nbr),
    INDEX idx_acumatica_po_source (source_type, source_id),
    INDEX idx_acumatica_po_requested_by (requested_by),

    CONSTRAINT fk_acumatica_po_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Purchase orders Quack Track has asked Acumatica to create (always as Hold/draft)';
