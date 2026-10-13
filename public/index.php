<?php
require __DIR__ . '/../src/bootstrap.php';

$pageTitle  = 'Home';
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
?>
<!--
  Frontend markup to be implemented.

  Available to this template:
    $site       - site settings array (name, tagline, about, address, phone, email, hours)
    $categories - menu items grouped by category name; each item has name, description, price
    $error      - string message when the menu could not be loaded, otherwise null

  Escape all output with e(). A reference implementation of the previous
  design lives in frontend-reference/public/index.php.
-->
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
