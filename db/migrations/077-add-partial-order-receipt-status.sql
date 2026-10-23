-- A mixed supply-and-asset order is only fully received when every line is reconciled.

ALTER TABLE purchase_orders
    MODIFY COLUMN status ENUM('draft', 'sent', 'partially_received', 'received', 'cancelled') NOT NULL DEFAULT 'draft';
