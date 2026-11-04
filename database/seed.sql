-- DRAFT seed data. Kevin owns this file and should replace it with the real menu.
INSERT INTO categories (id, name, display_order) VALUES
  (1, 'Breakfast', 1), (2, 'Mains', 2), (3, 'Drinks', 3);

INSERT INTO menu_items (category_id, name, description, price) VALUES
  (1, 'Mandazi & Masala Chai', 'Freshly fried mandazi with spiced milk tea.', 100),
  (1, 'Spanish Omelette', 'Eggs, potato, onion and tomato, served with toast.', 180),
  (1, 'Honey Pancakes', 'Fluffy pancakes with local honey and banana.', 200),
  (1, 'Smokies & Eggs', 'Two smokies, scrambled eggs and kachumbari.', 150),
  (2, 'Nyama Choma Plate', 'Grilled goat with ugali and kachumbari.', 400),
  (2, 'Chicken Pilau', 'Spiced rice with tender chicken and a side salad.', 450),
  (2, 'Beef Stew & Ugali', 'Slow-cooked beef stew with ugali and sukuma wiki.', 250),
  (2, 'Fried Tilapia', 'Whole tilapia with ugali and greens.', 500),
  (3, 'Kenyan Coffee', 'Single-origin filter coffee.', 70),
  (3, 'Fresh Passion Juice', 'Cold-pressed passion fruit juice.', 600),
  (3, 'Dawa Tea', 'Ginger, honey and lemon tea.', 50),
  (3, 'Iced Mocha', 'Espresso, chocolate and milk over ice.', 100);

-- The tables in the restaurant, grouped by the part of the room they stand in.
INSERT INTO restaurant_tables (label, seats, area) VALUES
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

-- Photos. Each file lives in images/; the full-size originals are
-- kept in assets/originals/ and are not served. A dish with no photo falls
-- back to a tile with its initial, so this list only needs the ones you have.
UPDATE menu_items SET image = 'mandazi-and-masala-chai.jpg', image_alt = 'Freshly fried mandazi with spiced milk tea'
 WHERE name = 'Mandazi & Masala Chai';
UPDATE menu_items SET image = 'spanish-omelette.jpg', image_alt = 'Eggs, potato, onion and tomato, served with toast'
 WHERE name = 'Spanish Omelette';
UPDATE menu_items SET image = 'honey-pancakes.jpg', image_alt = 'Fluffy pancakes with local honey and banana'
 WHERE name = 'Honey Pancakes';
UPDATE menu_items SET image = 'smokies-and-eggs.jpg', image_alt = 'Two smokies, scrambled eggs and kachumbari'
 WHERE name = 'Smokies & Eggs';
UPDATE menu_items SET image = 'nyama-choma-plate.jpg', image_alt = 'Grilled goat with ugali and kachumbari'
 WHERE name = 'Nyama Choma Plate';
UPDATE menu_items SET image = 'chicken-pilau.jpg', image_alt = 'Spiced rice with tender chicken and a side salad'
 WHERE name = 'Chicken Pilau';
UPDATE menu_items SET image = 'beef-stew-and-ugali.jpg', image_alt = 'Slow-cooked beef stew with ugali and sukuma wiki'
 WHERE name = 'Beef Stew & Ugali';
UPDATE menu_items SET image = 'fried-tilapia.jpg', image_alt = 'Whole tilapia with ugali and greens'
 WHERE name = 'Fried Tilapia';
UPDATE menu_items SET image = 'kenyan-coffee.jpg', image_alt = 'Single-origin filter coffee'
 WHERE name = 'Kenyan Coffee';
UPDATE menu_items SET image = 'fresh-passion-juice.jpg', image_alt = 'Cold-pressed passion fruit juice'
 WHERE name = 'Fresh Passion Juice';
UPDATE menu_items SET image = 'dawa-tea.jpg', image_alt = 'Ginger, honey and lemon tea'
 WHERE name = 'Dawa Tea';
UPDATE menu_items SET image = 'iced-mocha.jpg', image_alt = 'Espresso, chocolate and milk over ice'
 WHERE name = 'Iced Mocha';
