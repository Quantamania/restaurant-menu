-- ===========================================================================
-- Brings an OLDER database up to date. A brand new database does not need it:
-- schema.sql already contains everything.
--
-- Run it with --force, which tells mysql to keep going after an error:
--
--   Linux:    mariadb --force -u menu_user -p restaurant_menu < database/upgrade.sql
--   Windows:  mysql --force -u root -p restaurant_menu < database\\upgrade.sql
--
-- Why --force? If you have already run part of this before, MySQL will say
-- "Duplicate column name" and stop. That error is harmless — it means that
-- piece is already in place — and --force lets the rest finish.
--
-- Running the whole file twice is safe: every step either creates something
-- that is missing, or sets a value to what it already is.
--
-- Nothing here deletes a reservation, an order or a menu item.
-- ===========================================================================

-- 1. Tables in the restaurant ----------------------------------------------

CREATE TABLE IF NOT EXISTS restaurant_tables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(20) NOT NULL UNIQUE,
  seats INT NOT NULL,
  area VARCHAR(50) NOT NULL DEFAULT 'Indoor',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A floor-plan view was tried and removed again. These columns are no longer
-- used by any page. ("Can't DROP" here means they were never added.)

ALTER TABLE restaurant_tables DROP COLUMN pos_x;
ALTER TABLE restaurant_tables DROP COLUMN pos_y;
ALTER TABLE restaurant_tables DROP COLUMN shape;

-- 2. Reservations get a seating time and a table ---------------------------
--    ("Duplicate column name" here means this is already done.)

ALTER TABLE reservations
  ADD COLUMN reservation_time TIME NOT NULL DEFAULT '19:00:00' AFTER reservation_date;

ALTER TABLE reservations
  ADD COLUMN table_id INT NULL AFTER reservation_time;

ALTER TABLE reservations
  ADD CONSTRAINT fk_reservation_table
      FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL;

ALTER TABLE reservations
  ADD UNIQUE KEY uniq_table_slot (table_id, reservation_date, reservation_time);

-- 2b. The very first layout used labels T1-T8 in Window/Indoor areas. Rename
--     those rows into the new floor plan so their bookings are kept. The area
--     check matters: the new plan also has a T1-T5, out on the terrace, and
--     these must not touch them.

UPDATE restaurant_tables SET label='B1' WHERE label='T1' AND area='Window';
UPDATE restaurant_tables SET label='B2' WHERE label='T2' AND area='Window';
UPDATE restaurant_tables SET label='M1' WHERE label='T3' AND area='Indoor';
UPDATE restaurant_tables SET label='M2' WHERE label='T4' AND area='Indoor';
UPDATE restaurant_tables SET label='M5' WHERE label='T5' AND area='Indoor';
UPDATE restaurant_tables SET label='M4' WHERE label='T6' AND area='Terrace';
UPDATE restaurant_tables SET label='T1' WHERE label='T7' AND area='Terrace';
UPDATE restaurant_tables SET label='P1' WHERE label='T8' AND area='Private room';

-- A table from an in-between version that the room no longer has.
DELETE FROM restaurant_tables WHERE label='M7';

-- 2c. Add any table the room is missing ------------------------------------

INSERT IGNORE INTO restaurant_tables (label, seats, area) VALUES
  ('P1', 10, 'Private room'),
  ('B1',  2, 'Banquette'),
  ('B2',  2, 'Banquette'),
  ('B3',  2, 'Banquette'),
  ('B4',  2, 'Banquette'),
  ('M1',  4, 'Main floor'),
  ('M2',  4, 'Main floor'),
  ('M3',  4, 'Main floor'),
  ('M4',  4, 'Main floor'),
  ('M5',  6, 'Main floor'),
  ('M6',  2, 'Main floor'),
  ('S1',  2, 'Bar'),
  ('S2',  2, 'Bar'),
  ('S3',  2, 'Bar'),
  ('T5',  2, 'Terrace'),
  ('T1',  6, 'Terrace'),
  ('T2',  2, 'Terrace'),
  ('T3',  2, 'Terrace'),
  ('T4',  2, 'Terrace');

-- 2d. Make sure every table has the right size and area. Safe to re-run.

UPDATE restaurant_tables SET seats=10, area='Private room' WHERE label='P1';
UPDATE restaurant_tables SET seats= 2, area='Banquette' WHERE label='B1';
UPDATE restaurant_tables SET seats= 2, area='Banquette' WHERE label='B2';
UPDATE restaurant_tables SET seats= 2, area='Banquette' WHERE label='B3';
UPDATE restaurant_tables SET seats= 2, area='Banquette' WHERE label='B4';
UPDATE restaurant_tables SET seats= 4, area='Main floor' WHERE label='M1';
UPDATE restaurant_tables SET seats= 4, area='Main floor' WHERE label='M2';
UPDATE restaurant_tables SET seats= 4, area='Main floor' WHERE label='M3';
UPDATE restaurant_tables SET seats= 4, area='Main floor' WHERE label='M4';
UPDATE restaurant_tables SET seats= 6, area='Main floor' WHERE label='M5';
UPDATE restaurant_tables SET seats= 2, area='Main floor' WHERE label='M6';
UPDATE restaurant_tables SET seats= 2, area='Bar' WHERE label='S1';
UPDATE restaurant_tables SET seats= 2, area='Bar' WHERE label='S2';
UPDATE restaurant_tables SET seats= 2, area='Bar' WHERE label='S3';
UPDATE restaurant_tables SET seats= 2, area='Terrace' WHERE label='T5';
UPDATE restaurant_tables SET seats= 6, area='Terrace' WHERE label='T1';
UPDATE restaurant_tables SET seats= 2, area='Terrace' WHERE label='T2';
UPDATE restaurant_tables SET seats= 2, area='Terrace' WHERE label='T3';
UPDATE restaurant_tables SET seats= 2, area='Terrace' WHERE label='T4';

-- 3. Orders become their own feature ---------------------------------------
--    Ordering food and booking a table used to share one table. They are
--    separate pages now, so orders get their own.

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  order_type ENUM('collection','delivery') NOT NULL DEFAULT 'collection',
  address VARCHAR(255) NULL,
  order_date DATE NOT NULL,
  order_time TIME NOT NULL,
  notes TEXT NULL,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('new','preparing','ready','completed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3b. Orders grow a customer-facing reference, a delivery fee, and the
--     indexes the kitchen screen needs.
--     ("Duplicate column name" / "Duplicate key name" means already done.)

ALTER TABLE orders ADD COLUMN reference VARCHAR(12) NULL AFTER id;
ALTER TABLE orders ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER notes;
ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER subtotal;
ALTER TABLE orders MODIFY COLUMN status
  ENUM('new','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'new';

-- Any order placed before references existed gets one built from its id.
UPDATE orders SET reference = CONCAT('ZW-', LPAD(id, 6, '0')) WHERE reference IS NULL OR reference = '';
UPDATE orders SET subtotal = total WHERE subtotal = 0 AND total > 0;

ALTER TABLE orders MODIFY COLUMN reference VARCHAR(12) NOT NULL;
ALTER TABLE orders ADD UNIQUE KEY uniq_reference (reference);
ALTER TABLE orders ADD INDEX idx_kitchen (order_date, order_time);
ALTER TABLE orders ADD INDEX idx_status (status, order_date);
ALTER TABLE reservations ADD INDEX idx_day (reservation_date);

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  menu_item_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(8,2) NOT NULL,
  FOREIGN KEY (order_id)     REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The old combined table is no longer used by any page.
DROP TABLE IF EXISTS reservation_items;


-- 4. Payments, notifications and tracking codes -----------------------------
--
-- Added when the site grew a payment screen and started sending real email
-- and SMS. Run with --force as above: the two ADD COLUMNs will complain
-- "Duplicate column name" if you have already run this, and that is fine.

ALTER TABLE orders ADD COLUMN payment_method ENUM('mpesa','cash') NULL AFTER total;
ALTER TABLE orders
  ADD COLUMN payment_status ENUM('unpaid','pending','paid','failed')
  NOT NULL DEFAULT 'unpaid' AFTER payment_method;
ALTER TABLE orders ADD INDEX idx_owed (payment_status, order_date);

-- Orders taken before there was a payment screen were all paid in person.
-- Leaving them 'unpaid' would make the till think money is owed on food
-- that was handed over weeks ago.
UPDATE orders
   SET payment_method = 'cash', payment_status = 'paid'
 WHERE payment_method IS NULL
   AND status = 'completed';

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  method ENUM('mpesa','cash') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  phone VARCHAR(30) NULL,
  provider_ref VARCHAR(64) NULL UNIQUE,
  provider_note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NULL,
  channel ENUM('email','sms') NOT NULL,
  purpose VARCHAR(40) NOT NULL,
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error VARCHAR(255) NULL,
  provider_ref VARCHAR(120) NULL,
  driver VARCHAR(20) NOT NULL DEFAULT 'log',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE SET NULL,
  INDEX idx_order (order_id, created_at),
  INDEX idx_trouble (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_access_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  code_hash CHAR(64) NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  sent_to VARCHAR(190) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_codes_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_live (order_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 5. The kitchen screen -----------------------------------------------------

CREATE TABLE IF NOT EXISTS order_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  note VARCHAR(190) NULL,
  actor VARCHAR(60) NOT NULL DEFAULT 'kitchen',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_events_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_logins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARBINARY(16) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders that already exist have no history. Give each one a single opening
-- event so the kitchen screen does not show a blank timeline for them.
INSERT INTO order_events (order_id, from_status, to_status, note, actor, created_at)
SELECT o.id, NULL, o.status, 'Recorded before the kitchen screen existed', 'system', o.created_at
  FROM orders o
 WHERE NOT EXISTS (SELECT 1 FROM order_events e WHERE e.order_id = o.id);


-- 6. Customer accounts -----------------------------------------------------
--
-- Ordering now requires an account, and tracking an order means signing in
-- rather than quoting a reference and a one-time code.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope ENUM('staff','customer') NOT NULL,
  ip VARBINARY(16) NOT NULL,
  identity VARCHAR(190) NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip  (scope, ip, created_at),
  INDEX idx_who (scope, identity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carry the old staff rows over, so a lockout in progress is not forgotten
-- just because the table was renamed underneath it.
INSERT INTO login_attempts (scope, ip, identity, succeeded, created_at)
SELECT 'staff', ip, NULL, succeeded, created_at FROM staff_logins
 WHERE NOT EXISTS (SELECT 1 FROM login_attempts);

DROP TABLE IF EXISTS staff_logins;

ALTER TABLE orders ADD COLUMN user_id INT NULL AFTER created_at;
ALTER TABLE orders ADD INDEX idx_mine (user_id, id);
ALTER TABLE orders
  ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
  REFERENCES users(id) ON DELETE SET NULL;
