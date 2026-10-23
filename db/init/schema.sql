-- Schema for parts-shop
CREATE TABLE IF NOT EXISTS regions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  allow_registration TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  store_number VARCHAR(50) DEFAULT NULL UNIQUE,
  project_number VARCHAR(100) DEFAULT NULL,
  address TEXT,
  contact_info VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  first_name VARCHAR(80) DEFAULT NULL,
  last_name VARCHAR(80) DEFAULT NULL,
  username VARCHAR(80) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  region_id INT DEFAULT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'user',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
  UNIQUE KEY uk_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id VARCHAR(100) NOT NULL UNIQUE,
  barcode VARCHAR(100) DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  user_id INT NOT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  asset_type VARCHAR(100) DEFAULT NULL,
  manufacturer VARCHAR(100) DEFAULT NULL,
  status VARCHAR(50) DEFAULT NULL,
  color VARCHAR(50) DEFAULT NULL,
  order_number VARCHAR(100) DEFAULT NULL,
  modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_products_name (name),
  INDEX idx_products_barcode (barcode),
  INDEX idx_products_user (user_id),
  INDEX idx_products_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS van_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  user_id INT NOT NULL,
  store_id INT NOT NULL DEFAULT 1,
  quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_van_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_van_stock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_van_stock_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT,
  UNIQUE KEY uk_van_stock_user_product_store (user_id, product_id, store_id),
  INDEX idx_van_stock_user (user_id),
  INDEX idx_van_stock_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  user_id INT NOT NULL,
  change_qty INT NOT NULL,
  reason ENUM('add','use','adjust') NOT NULL,
  store_id INT DEFAULT NULL,
  store VARCHAR(190) DEFAULT NULL, -- Legacy field, will be deprecated
  note VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tx_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL,
  INDEX idx_transactions_user (user_id),
  INDEX idx_transactions_product (product_id),
  INDEX idx_transactions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS region_storage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  region_id INT NOT NULL,
  region VARCHAR(190) NOT NULL, -- Legacy field for backward compatibility
  location VARCHAR(255) NOT NULL DEFAULT '', -- Allows multiple locations per product per region
  quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_region_storage_product_region_location (product_id, region_id, location),
  CONSTRAINT fk_region_storage_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_region_storage_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
  INDEX idx_region_storage_region (region_id),
  INDEX idx_region_storage_location (region_id, location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordering system tables
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  order_number VARCHAR(50) NOT NULL UNIQUE,
  store_id INT NULL,
  notes TEXT,
  status ENUM('draft', 'sent', 'partially_received', 'received', 'cancelled') DEFAULT 'draft',
  receive_to_van TINYINT(1) NOT NULL DEFAULT 1,
  receive_to_region TINYINT(1) NOT NULL DEFAULT 0,
  total_items INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  CONSTRAINT fk_purchase_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_orders_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL,
  INDEX idx_purchase_orders_user (user_id),
  INDEX idx_purchase_orders_status (status),
  INDEX idx_purchase_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    notes VARCHAR(255),
    bill_to_store_id INT NULL,
    bill_to_store_key INT NOT NULL DEFAULT 0,
    receive_to_van_qty INT NOT NULL DEFAULT 0,
    receive_to_region_qty INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_order_items_order FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_order_items_bill_to_store FOREIGN KEY (bill_to_store_id) REFERENCES stores(id) ON DELETE SET NULL,
    UNIQUE KEY uk_order_product_bill_to (order_id, product_id, bill_to_store_key),
    INDEX idx_purchase_order_items_bill_to_store (bill_to_store_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_stock_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  destination ENUM('van', 'region') NOT NULL,
  quantity INT NOT NULL,
  target_user_id INT NULL,
  target_region_id INT NULL,
  store_id INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_order_receipt (order_id, product_id, destination),
  CONSTRAINT fk_order_receipt_order FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_receipt_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_receipt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_receipt_target_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_receipt_target_region FOREIGN KEY (target_region_id) REFERENCES regions(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_receipt_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    notes VARCHAR(255),
    bill_to_store_id INT NULL,
    bill_to_store_key INT NOT NULL DEFAULT 0,
    receive_to_van_qty INT NOT NULL DEFAULT 0,
    receive_to_region_qty INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cart_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_bill_to_store FOREIGN KEY (bill_to_store_id) REFERENCES stores(id) ON DELETE SET NULL,
    UNIQUE KEY uk_cart_user_product_bill_to (user_id, product_id, bill_to_store_key),
    INDEX idx_cart_items_updated (updated_at),
    INDEX idx_cart_items_bill_to_store (bill_to_store_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_favorites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  product_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_favorites_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uk_user_favorites (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  user_id INT NOT NULL,
  is_public BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_templates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_template_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  CONSTRAINT fk_order_template_items_template FOREIGN KEY (template_id) REFERENCES order_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_template_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uk_template_product (template_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default data
INSERT IGNORE INTO stores (id, name, address) VALUES (1, 'Unallocated', 'Default store for unallocated items');
