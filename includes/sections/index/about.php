<?php
/* The story, the numbers and the map card. */
?>
<section id="about" class="about">
  <div class="wrap about__grid">

    <div class="about__story">
      <p class="hero__kicker">Our story</p>
      <h2 class="about__title">
        Where we are,
        <span class="about__title-em">and who we are.</span>
      </h2>

      <p class="about__lede"><?= e($site['about']) ?></p>

      <ol class="trail">
        <li class="trail__step">
          <span class="trail__node" aria-hidden="true">01</span>
          <div class="trail__text">
            <h3 class="trail__title">Cooked to order</h3>
            <p>Nothing waits under a lamp for you.</p>
          </div>
        </li>
        <li class="trail__step">
          <span class="trail__node" aria-hidden="true">02</span>
          <div class="trail__text">
            <h3 class="trail__title">Bought that morning</h3>
            <p>We shop the market before we open.</p>
          </div>
        </li>
        <li class="trail__step">
          <span class="trail__node" aria-hidden="true">03</span>
          <div class="trail__text">
            <h3 class="trail__title">Room for everyone</h3>
            <p>Tables for two, up to parties of eight.</p>
          </div>
        </li>
      </ol>

      <ul class="picks about__picks">
        <li class="picks__item">
          <a class="picks__link" href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>">
            <span class="picks__thumb picks__thumb--none" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                   stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1Z"/>
              </svg>
            </span>
            <span class="picks__text">
              <span class="picks__name"><?= e($site['phone']) ?></span>
              <span class="picks__meta">Call us</span>
            </span>
          </a>
        </li>
        <li class="picks__item">
          <a class="picks__link" href="reserve.php">
            <span class="picks__thumb picks__thumb--none" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                   stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>
              </svg>
            </span>
            <span class="picks__text">
              <span class="picks__name">Book a table</span>
              <span class="picks__meta">Takes a minute</span>
            </span>
          </a>
        </li>
      </ul>
    </div>

    <div class="about__place">



        <figure class="mapcard">

        <div class="mapcard__frame">
        <?php if ($mapSrc): ?>
        <!-- OpenStreetMap needs no key and no account. If the visitor is
        offline the iframe stays blank, so the panel underneath it
        carries the address in writing either way. -->
        <iframe class="mapcard__map"
        src="<?= e($mapSrc) ?>"
        title="Map showing where <?= e($site['name']) ?> is"
        loading="lazy" referrerpolicy="no-referrer"></iframe>
        <?php else: ?>
        <p class="mapcard__none">Add a map block to content.php to show the map.</p>
        <?php endif; ?>

        <span class="mapcard__pin" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/>
        <circle cx="12" cy="10" r="2.6"/>
        </svg>
        </span>
        </div>

        <figcaption class="mapcard__body">
        <h3 class="mapcard__name"><?= e($site['name']) ?></h3>
        <address class="mapcard__address"><?= e($site['address']) ?></address>

        <div class="mapcard__rows">
        <a class="mapcard__row" href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>">
        <span class="mapcard__row-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1Z"/>
        </svg>
        </span>
        <span><?= e($site['phone']) ?></span>
        </a>
        <a class="mapcard__row" href="mailto:<?= e($site['email']) ?>">
        <span class="mapcard__row-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
        </svg>
        </span>
        <span><?= e($site['email']) ?></span>
        </a>
        </div>

        <?php if ($mapLink): ?>
        <a class="button button--block mapcard__go"
        href="<?= e($mapLink) ?>" target="_blank" rel="noopener noreferrer">
        Get directions
        </a>
        <?php endif; ?>
        </figcaption>

        </figure>
    </div>

  </div>
</section>

<!-- ============ HOURS ============ -->
<!-- Each day on its own card, the open and close times split apart so they
     can be read at a glance rather than as one run-on line. -->
