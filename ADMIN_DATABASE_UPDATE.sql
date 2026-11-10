-- ============================================================================
-- 3D EDGE admin: database update
-- Run ONCE in phpMyAdmin (or mysql) against the database used by Start_session.php.
-- Safe to re-run: every change checks first whether it is already there.
-- Back up the database before running.
--
-- What this does
--   * adds material/material_img/product columns the admin and website use
--   * adds the quote and order statuses the admin uses
--   * removes a JSON-only check on news.content that rejects plain text
--   * auto-numbering on product, material, product_img, material_img, news,
--     gallery_img and admin (fixes "Field ... doesn't have a default value")
--   * review.status        approve / reject / pending moderation (customer pages read it)
--   * news.image           image path for the home-page news card
--   * product.deleted_at   soft delete: hidden from the admin, kept in the database
--   * material.deleted_at  soft delete: hidden from the admin, kept in the database
--   * review.deleted_at    soft delete: hidden from the admin, kept in the database
--   * news_trash           deleted news items are moved here (not erased)
--   * gallery_img_trash    deleted gallery images are moved here (not erased)
--
-- Images are stored where the customer pages already look:
--   product_img.img_url / material_img.img_url / review_img  -> relative to admin/uploads/
--   news.image / gallery_img.gallery_image_url               -> relative to the site root
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Auto-numbering for the tables the admin inserts into.
-- Some databases were created without AUTO_INCREMENT, which makes every new row fail
-- with "Field ... doesn't have a default value". This makes sure the primary key
-- exists and auto-numbers. It changes nothing when the table is already correct.
-- ----------------------------------------------------------------------------

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `product` ADD PRIMARY KEY (`product_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `product` MODIFY `product_id` INT(11) NOT NULL AUTO_INCREMENT;

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `material` ADD PRIMARY KEY (`material_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `material` MODIFY `material_id` INT(11) NOT NULL AUTO_INCREMENT;

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_img' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `product_img` ADD PRIMARY KEY (`product_img_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `product_img` MODIFY `product_img_id` INT(11) NOT NULL AUTO_INCREMENT;

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_img' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `material_img` ADD PRIMARY KEY (`material_img_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `material_img` MODIFY `material_img_id` INT(11) NOT NULL AUTO_INCREMENT;

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `news` ADD PRIMARY KEY (`news_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `news` MODIFY `news_id` INT(11) NOT NULL AUTO_INCREMENT;

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gallery_img' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `gallery_img` ADD PRIMARY KEY (`gallery_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `gallery_img` MODIFY `gallery_id` INT(11) NOT NULL AUTO_INCREMENT;

SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `admin` ADD PRIMARY KEY (`admin_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `admin` MODIFY `admin_id` INT(11) NOT NULL AUTO_INCREMENT;

-- ----------------------------------------------------------------------------
-- Columns the admin and the customer pages use but this database is missing.
-- The website's materials and product pages read these (status, price_per_kg,
-- description, density, material_id, img_url, cover_img), so adding them keeps
-- both sides reading the same data. Existing rows keep their values.
-- ----------------------------------------------------------------------------
-- material
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material` ADD COLUMN `description` TEXT NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'description');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material` ADD COLUMN `density` DECIMAL(10,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'density');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material` ADD COLUMN `price_per_kg` DECIMAL(10,2) NOT NULL DEFAULT 0.00',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'price_per_kg');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material` ADD COLUMN `status` ENUM(''available'',''unavailable'') NOT NULL DEFAULT ''available''',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'created_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material` ADD COLUMN `updated_at` DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'updated_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- material_img (image path and cover flag)
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material_img` ADD COLUMN `img_url` VARCHAR(255) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_img' AND COLUMN_NAME = 'img_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `material_img` ADD COLUMN `cover_img` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_img' AND COLUMN_NAME = 'cover_img');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- product.material_id (links a product to its material)
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `product` ADD COLUMN `material_id` INT(11) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'material_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- product.model_url (optional .glb 3D model; the product page reads this)
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `product` ADD COLUMN `model_url` VARCHAR(255) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'model_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- news_chk_1: a CHECK constraint on news that rejects plain-text announcements
-- (for example json_valid(content)). It is removed only when it checks JSON.
-- Any other rule is kept. To see what your database has, run:
--   SELECT cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
--   FROM information_schema.TABLE_CONSTRAINTS tc
--   JOIN information_schema.CHECK_CONSTRAINTS cc
--     ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
--   WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'news' AND tc.CONSTRAINT_TYPE = 'CHECK';
SET @chk = (SELECT cc.CHECK_CLAUSE
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.CHECK_CONSTRAINTS cc
    ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
  WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'news'
    AND tc.CONSTRAINT_TYPE = 'CHECK' AND tc.CONSTRAINT_NAME = 'news_chk_1');
SET @s = IF(@chk IS NOT NULL AND LOWER(@chk) LIKE '%json_valid%',
  'ALTER TABLE news DROP CONSTRAINT news_chk_1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- order.tracking_num: courier references contain letters and hyphens, so store text.
-- Existing numeric values are kept as text. Re-running has no effect.
ALTER TABLE `order` MODIFY tracking_num VARCHAR(40) NULL;

-- Status lists the admin uses. These only ADD values, so existing rows are unaffected.
ALTER TABLE quote MODIFY quote_status ENUM('pending','reviewed','accepted','in_production','completed','declined') NOT NULL DEFAULT 'pending';
ALTER TABLE `order` MODIFY order_status ENUM('pending','paid','failed','shipping','delivered','cancelled') NOT NULL DEFAULT 'pending';

-- gallery_img_trash should be keyed too, so a restore picks exactly one row.
SET @pk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gallery_img_trash' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @s = IF(@pk = 0, 'ALTER TABLE `gallery_img_trash` ADD PRIMARY KEY (`gallery_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- review.status  (existing reviews become 'approved' so they stay visible;
--                 new reviews default to 'pending' until the admin approves them)
SET @s = (SELECT IF(COUNT(*)=0,
  "ALTER TABLE review ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'",
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review' AND COLUMN_NAME = 'status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE review MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending';

-- news.image
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE news ADD COLUMN image VARCHAR(255) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND COLUMN_NAME = 'image');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- deleted_at on product, material and review (soft delete)
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE product ADD COLUMN deleted_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'deleted_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE material ADD COLUMN deleted_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material' AND COLUMN_NAME = 'deleted_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE review ADD COLUMN deleted_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review' AND COLUMN_NAME = 'deleted_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Trash tables for news and gallery (same columns as the live tables, plus deleted_at)
CREATE TABLE IF NOT EXISTS news_trash LIKE news;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE news_trash ADD COLUMN deleted_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_trash' AND COLUMN_NAME = 'deleted_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS gallery_img_trash LIKE gallery_img;
SET @s = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE gallery_img_trash ADD COLUMN deleted_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gallery_img_trash' AND COLUMN_NAME = 'deleted_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ----------------------------------------------------------------------------
-- FAQ: questions and answers the admin writes. Hidden or deleted questions are not shown
-- on the website. Created only if missing; the five starter questions are added only
-- when the table is empty.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faq (
  faq_id       INT(11) NOT NULL AUTO_INCREMENT,
  question     VARCHAR(255) NOT NULL,
  answer       TEXT NOT NULL,
  sort_order   INT(11) NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL,
  PRIMARY KEY (faq_id),
  KEY idx_faq_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO faq (question, answer, sort_order, is_published)
SELECT t.question, t.answer, t.sort_order, 1 FROM (
  SELECT 'What file formats do you accept?' AS question,
         'We accept STL, OBJ, STEP, IGES, and most standard CAD file formats.' AS answer, 1 AS sort_order
  UNION ALL SELECT 'How long does printing take?',
         'Standard turnaround is 3-5 business days depending on the size and complexity of the part.', 2
  UNION ALL SELECT 'What''s your minimum order value?',
         'There is no minimum order value. We are happy to print one-off parts and prototypes.', 3
  UNION ALL SELECT 'Do you offer design services?',
         'Yes, we offer complete CAD design and reverse engineering services.', 4
  UNION ALL SELECT 'What materials are available?',
         'We offer a wide range of engineering-grade plastics including PLA, ABS, PETG, TPU, and Nylon.', 5
) t
WHERE NOT EXISTS (SELECT 1 FROM faq);

-- ----------------------------------------------------------------------------
-- Colours, sizes and product groups, as the website uses them.
-- Each colour or size of a product is its own product row. Rows that share a
-- group_code appear together on the product page.
-- ----------------------------------------------------------------------------
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE product ADD COLUMN colour VARCHAR(60) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'colour');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE product ADD COLUMN size VARCHAR(60) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'size');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE product ADD COLUMN group_code VARCHAR(60) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'group_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- A filament's colours are a comma-separated text list ("Black, White"), as the website reads them.
ALTER TABLE material MODIFY material_color VARCHAR(255) NULL;

-- ----------------------------------------------------------------------------
-- Filaments in the cart: one line per purchase, with the grams and the colour chosen.
-- ----------------------------------------------------------------------------
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE cart_item ADD COLUMN grams DECIMAL(10,2) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_item' AND COLUMN_NAME = 'grams');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE cart_item ADD COLUMN colour_name VARCHAR(60) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_item' AND COLUMN_NAME = 'colour_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
