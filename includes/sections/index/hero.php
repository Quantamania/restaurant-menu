<?php
/* The landing hero: the headline, the two buttons and the plate. */
?>
<section class="hero">

  <!-- The big teal block on the right. Decoration, so it is hidden from
       screen readers; everything it carries is real markup on top of it. -->
  <span class="hero__slab" aria-hidden="true"></span>

  <div class="wrap hero__grid">

    <div class="hero__text">
      <p class="hero__kicker">Are you hungry?</p>
      <h1 class="hero__title">Don&rsquo;t wait.</h1>
      <p class="hero__lede"><?= e($site['tagline']) ?></p>

      <div class="hero__actions">
        <a class="button button--outline" href="menu.php">Check out the menu</a>
        <a class="button button--plain" href="order.php">Order food</a>
      </div>

      <!-- a few dishes, as a taste of the menu -->
      <ul class="picks">
        <?php foreach (array_slice($featured, 0, 3) as $dish): ?>
          <?php
            $photo = dish_photo($dish['image'] ?? null);
          ?>
          <li class="picks__item">
            <a class="picks__link" href="menu.php#item-<?= (int) $dish['id'] ?>">
              <?php if ($photo): ?>
                <img class="picks__thumb" src="images/<?= e($photo) ?>"
                     alt="" loading="lazy" width="48" height="48">
              <?php else: ?>
                <span class="picks__thumb picks__thumb--none" aria-hidden="true">
                  <?= e(mb_strtoupper(mb_substr($dish['name'], 0, 1))) ?>
                </span>
              <?php endif; ?>
              <span class="picks__text">
                <span class="picks__name"><?= e($dish['name']) ?></span>
                <span class="picks__meta"><?= e($dish['category_name']) ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="hero__art">
      <!-- the plate -->
      <div class="plate-art">
        <span class="plate-art__ring" aria-hidden="true"></span>
        <?php
          // the dish named in content.php.
          $lead = $heroDish ?? ($featured[0] ?? null);
        ?>
        <?php
          $leadPhoto = $lead ? dish_photo($lead['image'] ?? null) : null;
          $leadHas   = $leadPhoto !== null;
        ?>
        <span class="plate-art__disc">
          <?php if ($leadHas): ?>
            <img src="images/<?= e($leadPhoto) ?>" alt="<?= e($lead['name']) ?>"
                 width="420" height="420">
          <?php elseif ($lead): ?>
            <span class="plate-art__letter" aria-hidden="true">
              <?= e(mb_strtoupper(mb_substr($lead['name'], 0, 1))) ?>
            </span>
          <?php endif; ?>
        </span>
      </div>

      <!-- the two counts, sitting on the teal -->
      <ul class="stats">
        <li class="stats__item">
          <span class="stats__label">On the menu</span>
          <strong class="stats__value"><?= $dishCount ?: '&mdash;' ?></strong>
        </li>
        <li class="stats__item">
          <span class="stats__label">Ready in</span>
          <strong class="stats__value"><?= (int) $site['ordering']['lead_minutes'] ?><span>min</span></strong>
        </li>
      </ul>
    </div>

  </div>

  <!-- the small rail down the right edge -->
  <ul class="edgerail">
    <li>
      <a class="edgerail__dot" href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>"
         aria-label="Call <?= e($site['phone']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1Z"/>
        </svg>
      </a>
    </li>
    <li>
      <a class="edgerail__dot" href="mailto:<?= e($site['email']) ?>" aria-label="Email us">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
        </svg>
      </a>
    </li>
    <li>
      <a class="edgerail__dot" href="reserve.php" aria-label="Reserve a table">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>
        </svg>
      </a>
    </li>
  </ul>

</section>

<!-- ============ THE THREE THINGS YOU CAN DO ============ -->
