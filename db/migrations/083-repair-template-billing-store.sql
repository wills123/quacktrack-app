-- Restore the billing column promised by migration 030 when that migration stopped
-- on an already-present cart column. Existing complete installations remain unchanged.
SET @qt_template_billing_sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_template_items'
          AND COLUMN_NAME = 'bill_to_store_id'
    ),
    'SELECT 1',
    'ALTER TABLE order_template_items ADD COLUMN bill_to_store_id INT NULL AFTER quantity'
);
PREPARE qt_template_billing_stmt FROM @qt_template_billing_sql;
EXECUTE qt_template_billing_stmt;
DEALLOCATE PREPARE qt_template_billing_stmt;

SET @qt_template_billing_sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_template_items'
          AND COLUMN_NAME = 'bill_to_store_id' AND REFERENCED_TABLE_NAME = 'stores'
          AND REFERENCED_COLUMN_NAME = 'id'
    ),
    'SELECT 1',
    'ALTER TABLE order_template_items ADD CONSTRAINT fk_template_items_bill_to_store FOREIGN KEY (bill_to_store_id) REFERENCES stores(id) ON DELETE SET NULL'
);
PREPARE qt_template_billing_stmt FROM @qt_template_billing_sql;
EXECUTE qt_template_billing_stmt;
DEALLOCATE PREPARE qt_template_billing_stmt;
