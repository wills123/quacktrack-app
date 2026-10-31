-- Migration: Create purchasing emails configuration table
-- Date: 2025-12-30
-- Description: Store purchasing email addresses with customizable templates for order emails

CREATE TABLE IF NOT EXISTS purchasing_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE COMMENT 'Purchasing email address',
    label VARCHAR(100) NOT NULL COMMENT 'Display name for dropdown',
    cc_addresses TEXT NULL COMMENT 'Comma-separated CC addresses',
    subject_template TEXT NOT NULL COMMENT 'Email subject with {{VARIABLES}}',
    body_template TEXT NOT NULL COMMENT 'Email body with {{VARIABLES}}',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status',
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default selection',
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Display order',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchasing_emails_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchasing_emails_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_active (is_active),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default template based on current generateOrderEmail() function format
INSERT INTO purchasing_emails (
    email,
    label,
    cc_addresses,
    subject_template,
    body_template,
    is_active,
    is_default,
    sort_order
) VALUES (
    'purchasing@company.com',
    'Default Purchasing',
    NULL,
    'Purchase Order Request - {{STORE_NAME}}',
    'Purchase Order Request
========================

SHIPPING DESTINATION:
Store: {{STORE_NAME}}
Region: {{REGION}}
Date: {{DATE}}
Order #: {{ORDER_NUMBER}}
========================

{{ITEMS_LIST}}

{{ORDER_NOTES}}

Thank you!',
    1,
    1,
    0
);
