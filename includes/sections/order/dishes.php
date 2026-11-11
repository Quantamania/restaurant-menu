<?php
/* Choosing the food: the menu, grouped by category, with a quantity box
 * against each dish. Included by order.php. */
?>
<section class="block">
  <header class="block__head">
    <div>
      <h2 class="block__title">Categories</h2>
      <p class="block__sub"><?= count($menu) ?> courses on the menu today</p>
    </div>
    <a class="block__more" href="menu.php">
      See the menu
      <span aria-hidden="true">&rsaquo;</span>
    </a>
  </header>

    <div class="cats" role="group" aria-label="Filter by category">
      <button class="cat is-on" type="button" data-filter="*">
        <span class="cat__disc cat__disc--all" aria-hidden="true">All</span>
        <span class="cat__name">Everything</span>
        <span class="cat__count"><?= array_sum(array_map('count', $menu)) ?></span>
      </button>

      <?php foreach ($menu as $categoryName => $items): ?>
        <?php
          // A course is shown by a dish from it rather than by its initial letter.
          $facePhoto = '';
          foreach ($items as $item) {
              $candidate = dish_photo($item['image'] ?? null);
              if ($candidate !== null) {
                  $facePhoto = $candidate;
                  break;
              }
          }
        ?>
        <button class="cat" type="button" data-filter="<?= e($categoryName) ?>">
          <span class="cat__disc" aria-hidden="true">
            <?php if ($facePhoto !== ''): ?>
              <img class="cat__photo" src="images/<?= e($facePhoto) ?>"
                   alt="" loading="lazy" width="88" height="88">
            <?php else: ?>
              <?= e(mb_strtoupper(mb_substr($categoryName, 0, 1))) ?>
            <?php endif; ?>
          </span>
          <span class="cat__name"><?= e($categoryName) ?></span>
          <span class="cat__count"><?= count($items) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
</section>

<section class="block">
  <header class="block__head">
    <div>
      <h2 class="block__title">Dishes</h2>
      <p class="block__sub"><span data-result-count></span> to choose from</p>
    </div>
    <a class="block__more" href="#details">
      Your details
      <span aria-hidden="true">&rsaquo;</span>
    </a>
  </header>

  <?php if (!empty($errors['items'])): ?>
    <p class="msg msg--bad">
      <span class="msg__icon" aria-hidden="true">!</span>
      <span><?= e($errors['items']) ?></span>
    </p>
  <?php endif; ?>

  <!-- The same rows as the menu page, with a stepper on the end.
       Not small cards: a dish reads better as a line you can scan. -->
  <ul class="dishes">
    <?php foreach ($menu as $categoryName => $items): ?>
      <?php foreach ($items as $item): ?>
        <?php
          $qty   = (int) ($quantities[$item['id']] ?? 0);
          $photo = dish_photo($item['image'] ?? null);
        ?>
        <li class="dish<?= $qty > 0 ? ' is-chosen' : '' ?>"
            id="item-<?= (int) $item['id'] ?>"
            data-pick
            data-name="<?= e($item['name']) ?>"
            data-desc="<?= e(mb_strimwidth((string) $item['description'], 0, 34, '…')) ?>"
            data-photo="<?= $photo ? e('images/' . basename($photo)) : '' ?>"
            data-price="<?= (float) $item['price'] ?>"
            data-course="<?= e($categoryName) ?>">

          <?php if ($photo): ?>
            <img class="dish__photo" src="images/<?= e($photo) ?>"
                 alt="" loading="lazy" width="112" height="112">
          <?php else: ?>
            <span class="dish__photo dish__photo--none" aria-hidden="true">
              <?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?>
            </span>
          <?php endif; ?>

          <div class="dish__text">
            <h3 class="dish__name">
              <label for="qty-<?= (int) $item['id'] ?>"><?= e($item['name']) ?></label>
            </h3>
            <?php if (trim((string) $item['description']) !== ''): ?>
              <p class="dish__desc"><?= e($item['description']) ?></p>
            <?php endif; ?>
          </div>

          <p class="dish__price">KSh <?= number_format((float) $item['price'], 0) ?></p>

          <div class="qty" data-qty>
            <input class="qty__input" type="number" min="0"
                   max="<?= (int) $rules['max_per_dish'] ?>" step="1"
                   id="qty-<?= (int) $item['id'] ?>"
                   name="qty[<?= (int) $item['id'] ?>]"
                   value="<?= $qty ?>"
                   aria-label="Quantity of <?= e($item['name']) ?>">
          </div>

          <span class="dish__badge" data-card-badge<?= $qty > 0 ? '' : ' hidden' ?>><?= $qty ?></span>
        </li>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </ul>

  <p class="dish-grid__empty" data-no-results hidden>
    Nothing on the menu matches that.
  </p>
</section>
