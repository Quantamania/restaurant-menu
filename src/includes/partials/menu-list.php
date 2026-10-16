<?php
/**
 * Home page — the menu, grouped by category. The core of the site.
 *
 * Available here:
 *   $categories - array keyed by category name; each value is a list of items.
 *                 Each item has: name, description, price.
 *   $error      - string if the menu could not be loaded, otherwise null.
 *
 * Handle all three states: the error, the empty menu, and the normal list.
 * Prices are plain numbers from the database — format them, for example
 * 'KSh ' . number_format((float) $item['price'], 0)
 *
 * Styles: public/css/home.css
 */
?>
<section id="menu" class="menu">
  <h2 class="section-title">Our Menu</h2>

  <?php if ($error): ?>
    <p class="menu-error"><?= e($error) ?></p>
  <?php elseif (!$categories): ?>
    <p class="menu-empty">No menu items yet.</p>
  <?php else: ?>
    <?php foreach ($categories as $categoryName => $items): ?>
      <section class="menu-category">
        <h3 class="category-name"><?= e($categoryName) ?></h3>
        <!-- TODO: lay out $items as cards or a list -->
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
