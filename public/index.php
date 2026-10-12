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
<section class="hero">
  <h1><?= e($site['name']) ?></h1>
  <p class="tagline"><?= e($site['tagline']) ?></p>
  <a class="button" href="reserve.php">Reserve a table</a>
</section>

<section id="menu" class="menu">
  <h2 class="section-title">Our Menu</h2>

  <?php if ($error): ?>
    <p class="menu-error"><?= e($error) ?></p>
  <?php elseif (!$categories): ?>
    <p class="menu-empty">No menu items yet.</p>
  <?php endif; ?>

  <?php foreach ($categories as $categoryName => $items): ?>
    <section class="menu-category">
      <h3 class="category-name"><?= e($categoryName) ?></h3>
      <ul class="menu-items">
        <?php foreach ($items as $item): ?>
          <li class="menu-item">
            <h4 class="item-name"><?= e($item['name']) ?></h4>
            <p class="item-description"><?= e($item['description']) ?></p>
            <span class="item-price">KSh <?= number_format((float) $item['price'], 0) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
</section>

<section id="about" class="about">
  <h2 class="section-title">About Us</h2>
  <p><?= e($site['about']) ?></p>
</section>

<section id="hours" class="hours">
  <h2 class="section-title">Hours &amp; Location</h2>
  <table class="hours-table">
    <tbody>
      <?php foreach ($site['hours'] as $days => $time): ?>
        <tr><th scope="row"><?= e($days) ?></th><td><?= e($time) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <address>
    <?= e($site['address']) ?><br>
    <?= e($site['phone']) ?><br>
    <?= e($site['email']) ?>
  </address>
</section>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
