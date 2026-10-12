-- DRAFT seed data. Kevin owns this file and should replace it with the real menu.
INSERT INTO categories (id, name, display_order) VALUES
  (1, 'Breakfast', 1), (2, 'Mains', 2), (3, 'Drinks', 3);

INSERT INTO menu_items (category_id, name, description, price) VALUES
  (1, 'Mandazi & Masala Chai', 'Freshly fried mandazi with spiced milk tea.', 250),
  (1, 'Spanish Omelette', 'Eggs, potato, onion and tomato, served with toast.', 450),
  (1, 'Honey Pancakes', 'Fluffy pancakes with local honey and banana.', 400),
  (1, 'Smokies & Eggs', 'Two smokies, scrambled eggs and kachumbari.', 350),
  (2, 'Nyama Choma Plate', 'Grilled goat with ugali and kachumbari.', 900),
  (2, 'Chicken Pilau', 'Spiced rice with tender chicken and a side salad.', 650),
  (2, 'Beef Stew & Ugali', 'Slow-cooked beef stew with ugali and sukuma wiki.', 550),
  (2, 'Fried Tilapia', 'Whole tilapia with ugali and greens.', 800),
  (3, 'Kenyan Coffee', 'Single-origin filter coffee.', 200),
  (3, 'Fresh Passion Juice', 'Cold-pressed passion fruit juice.', 250),
  (3, 'Dawa Tea', 'Ginger, honey and lemon tea.', 200),
  (3, 'Iced Mocha', 'Espresso, chocolate and milk over ice.', 350);
