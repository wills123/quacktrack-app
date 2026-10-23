CREATE DATABASE IF NOT EXISTS parts_shop;
USE parts_shop;

-- Create tables
-- Schema for parts-shop
CREATE TABLE IF NOT EXISTS regions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
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
  CONSTRAINT fk_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS van_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  user_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_van_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_van_stock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  user_id INT NOT NULL,
  change_qty INT NOT NULL,
  reason ENUM('add','use','adjust') NOT NULL,
  store VARCHAR(190) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tx_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS region_storage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  region VARCHAR(190) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY (product_id, region),
  CONSTRAINT fk_region_storage_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create initial admin user with bcrypt hash of 'QuackTrack2025!'
INSERT INTO regions (name) VALUES ('Admin Region');
INSERT INTO users (email, password_hash, role, region_id) 
VALUES ('willstrain1997@gmail.com', '$2y$10$KktG2BeAVSMfKJWDO.wkuue3QfwUaVLRZkzLIKNzEqSgT4AKQcZM.', 'admin', 
        (SELECT id FROM regions WHERE name = 'Admin Region'));
