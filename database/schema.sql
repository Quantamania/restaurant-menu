-- ===========================================================================
-- Creates every table the site needs. Safe to run more than once.
--
--   categories          the groups on the menu (Breakfast, Mains, Drinks)
--   menu_items          the dishes
--   restaurant_tables   the physical tables in the room
--   reservations        a booking: who, when, and which table
--   orders              a food order: who, when, collection or delivery
--   order_items         the dishes on an order
--   payments            every attempt to pay for an order
--   notifications       every email and SMS the site has tried to send
--   order_access_codes  one-time codes that unlock "track my order"
--   order_events        every status change an order has been through
--   users               a customer account: ordering requires one
--   login_attempts      sign-in attempts, for rate limiting both doors
--   sessions            session data, when the host has no shared disk
--
-- Booking a table and ordering food are two separate things. A visitor can
-- do either on its own, so they have their own tables.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  display_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  price DECIMAL(8,2) NOT NULL,
  image VARCHAR(255) NULL,
  image_alt VARCHAR(255) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The tables in the restaurant. "label" is what the customer sees (M1, B2),
-- "area" is the part of the room it stands in.
CREATE TABLE IF NOT EXISTS restaurant_tables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(20) NOT NULL UNIQUE,
  seats INT NOT NULL,
  area VARCHAR(50) NOT NULL DEFAULT 'Indoor',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  reservation_date DATE NOT NULL,
  reservation_time TIME NOT NULL DEFAULT '19:00:00',
  table_id INT NULL,
  party_size INT NULL,
  message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,

  -- The database itself refuses to book the same table twice in one slot.
  -- Two people clicking at the same moment cannot both win: the second
  -- INSERT fails, and reserve.php turns that into a friendly message.
  UNIQUE KEY uniq_table_slot (table_id, reservation_date, reservation_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- A customer account.
--
-- Ordering requires one. That is a deliberate trade — it is friction before
-- the first order — and what it buys is that "where is my food?" becomes
-- "sign in", instead of a reference code and a one-time password. An account
-- also means the order form can be filled in for you, and that a returning
-- customer's history is in one place.
--
-- email is the identity, so it is UNIQUE. The database refuses a second
-- account on the same address rather than leaving the PHP to check first and
-- hope nobody registers in the gap.
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  -- password_hash() output: bcrypt, with its salt and cost inside it. There
  -- is nowhere in this database that a readable password exists.
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A food order. Nothing to do with reservations — someone can order without
-- booking a table, and book a table without ordering.
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- What the customer quotes on the phone, e.g. ZW-7K3QB2. Never the id:
  -- an id tells a stranger how many orders you have taken.
  reference VARCHAR(12) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  order_type ENUM('collection','delivery') NOT NULL DEFAULT 'collection',
  address VARCHAR(255) NULL,
  order_date DATE NOT NULL,
  order_time TIME NOT NULL,
  notes TEXT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,      -- the food
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,  -- 0 for collection
  total DECIMAL(10,2) NOT NULL DEFAULT 0,         -- subtotal + delivery_fee

  -- Two states, deliberately kept apart.
  --
  --   status          how far the KITCHEN has got
  --   payment_status  whether the MONEY has arrived
  --
  -- They are not the same question and they do not move together: a paid
  -- order can still be waiting to be cooked, and a cash order is cooked
  -- and handed over long before it is paid. Folding them into one column
  -- forces nonsense states like "preparing or paid?", so they are separate.
  status ENUM('new','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'new',
  payment_method ENUM('mpesa','cash') NULL,
  payment_status ENUM('unpaid','pending','paid','failed') NOT NULL DEFAULT 'unpaid',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Who placed it. NULL only for orders taken before accounts existed; the
  -- order form requires a signed-in customer now.
  --
  -- ON DELETE SET NULL, not CASCADE: deleting an account must not delete the
  -- restaurant's record of food it actually cooked and was paid for. The
  -- name, email and phone are copied onto the order anyway, because they are
  -- what was true at the time — a customer who later changes their phone
  -- number has not changed the number the kitchen rang that evening.
  user_id INT NULL,

  -- The kitchen screen asks "what is due today, and what is still open?",
  -- so those two columns are indexed together.
  INDEX idx_kitchen (order_date, order_time),
  INDEX idx_status (status, order_date),
  -- "which orders are still owed money?" — the till's question.
  INDEX idx_owed (payment_status, order_date),
  -- "show me my orders, newest first" — every visit to the tracking page.
  INDEX idx_mine (user_id, id),

  CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Every attempt to pay for an order, successful or not.
--
-- Why a table and not just the two columns on orders: a payment is a
-- conversation with somebody else's system. It gets retried, it times out,
-- it fails and is tried again on another number. Keeping one row per
-- ATTEMPT means a failed try is history we can read back when a customer
-- says "but it took my money", instead of a value we overwrote.
--
-- orders.payment_status is the current answer; this table is how we got there.
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  method ENUM('mpesa','cash') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  -- The number the push was sent to. May differ from the order's phone:
  -- somebody else can pay for your lunch.
  phone VARCHAR(30) NULL,
  -- What the provider calls this transaction (M-Pesa's receipt number).
  --
  -- UNIQUE is the important part. Payment providers retry their callback
  -- until they get a 200, so the same receipt WILL arrive more than once.
  -- The unique key makes crediting one payment twice impossible at the
  -- database level, rather than something the PHP has to remember to check
  -- — the same reasoning as uniq_table_slot on reservations.
  provider_ref VARCHAR(64) NULL UNIQUE,
  provider_note VARCHAR(255) NULL,   -- the provider's own wording on a failure
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_payments_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,

  INDEX idx_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The dishes on an order.
-- unit_price is a copy of the price at the moment of ordering, so changing a
-- menu price later does not rewrite what an old customer was charged.
CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  menu_item_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(8,2) NOT NULL,
  FOREIGN KEY (order_id)     REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Everything the site has tried to send: order confirmations, payment
-- receipts, one-time codes.
--
-- An outbox, not just a log. Email and SMS leave the building and fail for
-- reasons we do not control — a wrong address, a provider outage, no credit
-- on the SMS account. If the only record is the customer saying "nothing
-- arrived", there is nothing to look at. One row per message, with the
-- provider's own error text kept verbatim, turns that into a question we
-- can answer.
--
-- In development the mail driver writes the row and stops there, so the
-- whole flow can be exercised with no credentials and no internet.
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NULL,                       -- NULL once an old order is purged
  channel ENUM('email','sms') NOT NULL,
  purpose VARCHAR(40) NOT NULL,            -- order_placed, payment_receipt, access_code
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NULL,               -- NULL for SMS: it has no subject
  body MEDIUMTEXT NOT NULL,
  status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error VARCHAR(255) NULL,                 -- the provider's wording, verbatim
  provider_ref VARCHAR(120) NULL,
  driver VARCHAR(20) NOT NULL DEFAULT 'log',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_notifications_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE SET NULL,

  INDEX idx_order (order_id, created_at),
  INDEX idx_trouble (status, created_at)   -- "what failed today?"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- One-time codes that let somebody read their own order.
--
-- Replaces "type the reference and the phone number". That check only ever
-- proved the typist knew a phone number; a code sent to the phone proves
-- they are holding it.
--
-- Three rules are baked into the columns rather than left to the PHP:
--   * code_hash, never the code     — a leaked database must not hand over
--                                     live codes, so we keep only a hash
--   * expires_at                    — a code is useless after ten minutes
--   * attempts                      — guessing six digits is cheap at
--                                     unlimited tries and hopeless at five
CREATE TABLE IF NOT EXISTS order_access_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  code_hash CHAR(64) NOT NULL,             -- sha256 of the six digits
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


-- Every status change an order has been through.
--
-- orders.status says where an order is NOW. This says how it got there, and
-- when, and who moved it. A kitchen runs on "when did that go out?" and
-- "who cancelled this?", and neither question can be answered by a single
-- column that gets overwritten.
--
-- It is also the honest record if a customer disputes a cancellation.
CREATE TABLE IF NOT EXISTS order_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  -- NULL for the first event (the order being created).
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  note VARCHAR(190) NULL,
  -- Who did it. One shared kitchen login today, so this is 'kitchen' or
  -- 'system'; when there are named staff accounts it becomes their name
  -- without the table changing shape.
  actor VARCHAR(60) NOT NULL DEFAULT 'kitchen',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_events_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,

  INDEX idx_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




-- Sign-in attempts, for rate limiting. One table for both doors.
--
-- 'staff' is the kitchen screen, 'customer' is an account. They need the same
-- defence and the same pruning, so they share a table rather than having two
-- near-identical ones.
--
-- Counted in the database and keyed on the IP, because a counter in the
-- session is no defence at all: a script guessing passwords simply does not
-- send the cookie back.
--
-- identity is the email that was tried, so a single account being attacked
-- can be slowed down even when the attempts come from many addresses. It is
-- NULL for the staff door, which has no username.
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope ENUM('staff','customer') NOT NULL,
  ip VARBINARY(16) NOT NULL,
  identity VARCHAR(190) NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_ip   (scope, ip, created_at),
  INDEX idx_who  (scope, identity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


/* Sessions, when there is no shared disk to keep them on (see src/session.php). */
CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  last_seen INT UNSIGNED NOT NULL,
  INDEX idx_gc (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
