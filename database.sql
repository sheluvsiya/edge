-- =====================================================================
-- 3D EDGE — DATABASE SCHEMA
-- Scan . Design . Print
--
-- Run this once to create the database and every table the admin
-- panel needs. The application works correctly with every table
-- empty — dashboard stats resolve to 0, list pages show their empty
-- state. Nothing below is required for the app to function; the
-- INSERT statements at the bottom are optional demo data only.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `3dedge` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `3dedge`;

-- ---------------------------------------------------------------------
-- Customers (public site users). Auth is not implemented yet — the
-- password column exists so the schema won't need to change later.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  password VARCHAR(255) DEFAULT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Admin staff accounts — not enforced yet, see admin/includes/auth.php
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) DEFAULT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'admin',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Catalogue
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  sku VARCHAR(60) NOT NULL,
  category_id INT UNSIGNED DEFAULT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
  stock_quantity INT NOT NULL DEFAULT 0,
  stock_status ENUM('in_stock','low_stock','out_of_stock') NOT NULL DEFAULT 'in_stock',
  image_path VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_name (name),
  KEY idx_products_active (is_active),
  KEY idx_products_stock_status (stock_status),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  image_path VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_services_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS materials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  specifications TEXT,
  color_options VARCHAR(255) DEFAULT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock_status ENUM('available','out_of_stock') NOT NULL DEFAULT 'available',
  recommended_settings TEXT,
  image_path VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_materials_stock_status (stock_status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Quote requests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quote_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(150) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  customer_phone VARCHAR(30) DEFAULT NULL,
  service_id INT UNSIGNED DEFAULT NULL,
  material_id INT UNSIGNED DEFAULT NULL,
  project_description TEXT,
  status ENUM('pending','under_review','requires_information','priced','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  estimated_price DECIMAL(10,2) DEFAULT NULL,
  estimated_production_time VARCHAR(100) DEFAULT NULL,
  admin_notes TEXT,
  rejection_reason TEXT,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_quotes_status (status),
  KEY idx_quotes_submitted (submitted_at),
  -- RESTRICT (not SET NULL): a service/material referenced by a quote must
  -- be marked inactive rather than deleted, so the quote's historical
  -- record stays intact. admin/services/delete.php and
  -- admin/materials/delete.php both catch this and tell the administrator
  -- to deactivate the record instead.
  CONSTRAINT fk_quotes_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
  CONSTRAINT fk_quotes_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quote_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_type VARCHAR(20) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_quote_files_quote (quote_id),
  CONSTRAINT fk_quote_files_quote FOREIGN KEY (quote_id) REFERENCES quote_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quote_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  previous_status VARCHAR(50) DEFAULT NULL,
  new_status VARCHAR(50) NOT NULL,
  changed_by VARCHAR(150) DEFAULT 'Administrator',
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  KEY idx_quote_history_quote (quote_id),
  CONSTRAINT fk_quote_history_quote FOREIGN KEY (quote_id) REFERENCES quote_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) NOT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  customer_phone VARCHAR(30) DEFAULT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  order_status ENUM('pending','confirmed','in_production','ready','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  shipping_address TEXT,
  delivery_method VARCHAR(100) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_status (order_status),
  KEY idx_orders_payment_status (payment_status),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED DEFAULT NULL,
  product_name VARCHAR(180) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  KEY idx_order_items_order (order_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  -- RESTRICT: a product that appears in an order's line items can't be
  -- deleted outright (that would corrupt historical order records) — see
  -- admin/products/delete.php, which catches this and asks the
  -- administrator to mark the product inactive instead.
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  previous_status VARCHAR(50) DEFAULT NULL,
  new_status VARCHAR(50) NOT NULL,
  changed_by VARCHAR(150) DEFAULT 'Administrator',
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  KEY idx_order_history_order (order_id),
  CONSTRAINT fk_order_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  payment_reference VARCHAR(100) DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_date DATETIME DEFAULT NULL,
  status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  KEY idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Reviews
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(150) NOT NULL,
  customer_email VARCHAR(190) DEFAULT NULL,
  product_id INT UNSIGNED DEFAULT NULL,
  rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
  review_text TEXT,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reviews_status (status),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Project gallery
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gallery_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT,
  industry VARCHAR(120) DEFAULT NULL,
  service_type VARCHAR(120) DEFAULT NULL,
  materials_used VARCHAR(255) DEFAULT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gallery_published (is_published)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gallery_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_gallery_images_project (project_id),
  CONSTRAINT fk_gallery_images_project FOREIGN KEY (project_id) REFERENCES gallery_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Blog / news
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(220) NOT NULL,
  short_description VARCHAR(500) DEFAULT NULL,
  content LONGTEXT,
  image_path VARCHAR(255) DEFAULT NULL,
  author VARCHAR(120) DEFAULT 'Administrator',
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_blog_slug (slug),
  KEY idx_blog_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- FAQs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(255) NOT NULL,
  answer TEXT NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT 'General',
  display_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_faqs_category (category),
  KEY idx_faqs_order (display_order)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Contact messages
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(200) DEFAULT NULL,
  message TEXT NOT NULL,
  status ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_messages_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Site settings / content (key-value)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT,
  UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB;

-- =====================================================================
-- OPTIONAL DEMO DATA — safe to delete. The app works with empty tables.
-- =====================================================================
INSERT INTO product_categories (name) VALUES ('3D Printers'), ('Printed Parts'), ('Accessories');

INSERT INTO services (name, description, is_active) VALUES
  ('CAD Design', 'Custom CAD modelling from sketches, references or reverse-engineered parts.', 1),
  ('Reverse Engineering', 'Scan an existing part and rebuild it as an editable, accurate CAD model.', 1),
  ('3D Printing', 'FDM printing in a wide range of engineering-grade materials.', 1),
  ('Rapid Prototyping', 'Fast-turnaround prototypes for design validation.', 1),
  ('Small Batch Manufacturing', 'Low-volume production runs of finished parts.', 1);

INSERT INTO materials (name, description, specifications, stock_status, price, is_active) VALUES
  ('PLA', 'Easy to print, biodegradable, good for prototypes and display parts.', 'Print temp 190-220C, bed 50-60C', 'available', 350.00, 1),
  ('PETG', 'Tougher than PLA with better temperature resistance.', 'Print temp 230-250C, bed 70-80C', 'available', 420.00, 1),
  ('ABS', 'Durable and heat resistant, needs an enclosed printer.', 'Print temp 230-250C, bed 90-110C', 'available', 400.00, 1),
  ('ASA', 'UV-resistant alternative to ABS, suited to outdoor parts.', 'Print temp 240-260C, bed 90-110C', 'available', 450.00, 1),
  ('TPU', 'Flexible rubber-like filament.', 'Print temp 210-230C, bed 40-60C, slow print speed', 'available', 480.00, 1),
  ('Nylon', 'High strength and abrasion resistance for functional parts.', 'Print temp 240-260C, bed 70-90C, dry storage required', 'available', 650.00, 1),
  ('Carbon Fibre', 'Reinforced composite filament for stiff, lightweight parts.', 'Print temp 250-270C, hardened nozzle required', 'available', 950.00, 1);

INSERT INTO site_settings (setting_key, setting_value) VALUES
  ('company_name', '3D Edge'),
  ('company_tagline', 'Scan . Design . Print'),
  ('company_email', 'info@3dedge.co.za'),
  ('company_phone', '066 216 6924'),
  ('company_address', '97 Edison Cres, Sunninghill Village Shopping Centre, Sandton, 2157'),
  ('business_hours', 'Mon-Fri 08:00-17:00, Sat 09:00-13:00'),
  ('vat_rate', '15'),
  ('currency', 'ZAR'),
  ('max_upload_mb', '50'),
  ('allowed_extensions', 'stl,step,stp,igs,iges,obj,zip'),
  ('about_us', 'FILL ME IN - company story.'),
  ('mission', 'FILL ME IN - mission statement.'),
  ('vision', 'FILL ME IN - vision statement.');
