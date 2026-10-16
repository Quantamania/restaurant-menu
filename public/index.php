<?php
require __DIR__ . '/../src/bootstrap.php';

$pageTitle  = 'Home';
$pageStyle  = 'home';
$categories = [];
$error      = null;

try {
    $rows = getPDO()->query(
        'SELECT c.name AS category_name, i.name, i.description, i.price
         FROM categories c
         JOIN menu_items i ON i.category_id = c.id
         WHERE i.is_available = 1
         ORDER BY c.display_order, i.name'
    )->fetchAll();

    foreach ($rows as $row) {
        $categories[$row['category_name']][] = $row;
    }
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $error = 'The menu is unavailable right now. Please try again later.';
}

require __DIR__ . '/../src/includes/header.php';

// Home page sections, in the order they appear.
require __DIR__ . '/../src/includes/partials/hero.php';
require __DIR__ . '/../src/includes/partials/menu-list.php';
require __DIR__ . '/../src/includes/partials/about.php';
require __DIR__ . '/../src/includes/partials/hours.php';

require __DIR__ . '/../src/includes/footer.php';
