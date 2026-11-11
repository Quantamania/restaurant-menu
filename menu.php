<?php
/* MENU PAGE */

require __DIR__ . '/includes/setup.php';

$pageTitle  = 'Menu';
$crumbs     = [['Home', 'index.php'], ['Menu', null]];
$categories = [];
$error      = null;

try {
    // Fetch every available dish, with the name of the category it belongs to.
    $rows = getPDO()->query(
        'SELECT c.name AS category_name, i.id, i.name, i.description, i.price,
                i.image, i.image_alt
         FROM categories c
         JOIN menu_items i ON i.category_id = c.id
         WHERE i.is_available = 1
         ORDER BY c.display_order, i.name'
    )->fetchAll();

    // Group the flat list of rows by category, so the page can loop over
    foreach ($rows as $row) {
        $categories[$row['category_name']][] = $row;
    }
} catch (Throwable $ex) {
    // Never show the raw database error to a visitor.
    error_log($ex->getMessage());
    $error = 'The menu is unavailable right now. Please try again later.';
}

require __DIR__ . '/includes/header.php';
?>

<!-- ============ MENU ============ -->
<section id="menu" class="section menu-section">
  <div class="wrap">

    <header class="masthead">
      <h1 class="masthead__word" aria-label="Menu">
        <span class="mast__big" aria-hidden="true">M</span>
        <span class="mast__stack" aria-hidden="true">
          <span class="mast__small">E</span>
          <span class="mast__name">
            <?php foreach (preg_split('/\s+/', $site['name']) as $word): ?>
              <span><?= e(mb_strtoupper($word)) ?></span>
            <?php endforeach; ?>
          </span>
        </span>
        <span class="mast__big" aria-hidden="true">N</span>
        <span class="mast__big" aria-hidden="true">U</span>
      </h1>
      <p class="masthead__sub">
        <?php
          // Stacked one word per line, so trailing commas and full stops are dropped.
          foreach (preg_split('/\s+/', trim($site['tagline'])) as $word):
            $word = trim($word, " \t.,;:!?");
            if ($word === '') { continue; }
        ?>
          <span><?= e($word) ?></span>
        <?php endforeach; ?>
      </p>
    </header>

    <?php if ($error): ?>

      <p class="msg msg--bad">
        <span class="msg__icon" aria-hidden="true">!</span>
        <span><?= e($error) ?></span>
      </p>

    <?php elseif (!$categories): ?>

      <p class="msg msg--empty">No menu items yet. Please check back soon.</p>

    <?php else: ?>

      <?php foreach ($categories as $categoryName => $items): ?>
        <section class="course">

          <header class="course__head">
            <span class="course__ornament" aria-hidden="true">
              <span class="course__ornament-rule"></span>
              <span class="course__ornament-mark"></span>
              <span class="course__ornament-rule"></span>
            </span>
            <h2 class="course__title"><?= e($categoryName) ?></h2>
          </header>

          <div class="course__grid">
            <?php foreach ($items as $item): ?>
              <article class="plate" id="item-<?= (int) $item['id'] ?>">

                <?php
                  // A photo if the kitchen has added one.
                  $photo = dish_photo($item['image'] ?? null);
                ?>
                <?php if ($photo): ?>
                  <img class="plate__photo" src="images/<?= e($photo) ?>"
                       alt="<?= e($item['image_alt'] ?: $item['name']) ?>" loading="lazy" width="104" height="104">
                <?php else: ?>
                  <span class="plate__photo plate__photo--none" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?>
                  </span>
                <?php endif; ?>

                <div class="plate__text">
                  <h3 class="plate__name"><?= e($item['name']) ?></h3>
                  <p class="plate__desc"><?= e($item['description']) ?></p>
                  <a class="plate__add"
                     href="order.php?item=<?= (int) $item['id'] ?>#item-<?= (int) $item['id'] ?>">
                    Add to order
                  </a>
                </div>

                <p class="plate__price">KSh <?= number_format((float) $item['price'], 0) ?></p>

              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
