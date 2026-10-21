<?php
/* ===========================================================================
 * LANDING PAGE
 *
 * The page people arrive on: what we are, where we are, and the three
 * things you can do — read the menu, order food, book a table.
 *
 * Part 1 (PHP)  - a few dishes to tease the menu with
 * Part 2 (HTML) - hero, the three cards, about with the map, hours, closing call
 * =========================================================================== */

require __DIR__ . '/../src/setup.php';

$pageTitle = 'Home';
$featured  = [];
$dishCount = 0;
$heroDish  = null;

try {
    // One dish from each course, so the three chips show the range of the
    // menu. Taking the three cheapest put two drinks in a row and left the
    // mains out altogether.
    //
    // The pick happens here rather than in SQL because "the cheapest in each
    // group" needs either a correlated subquery or a GROUP BY that upsets
    // strict mode — a short loop is easier to read and does the same job.
    $all = getPDO()->query(
        'SELECT i.id, i.name, i.description, i.price, i.image, i.image_alt,
                c.name AS category_name, c.display_order
         FROM menu_items i
         JOIN categories c ON c.id = i.category_id
         WHERE i.is_available = 1
         ORDER BY c.display_order, i.price'
    )->fetchAll();

    $featured = [];
    foreach ($all as $row) {
        // the rows arrive cheapest-first within each course, so the first
        // one seen for a course is the one to show
        if (!isset($featured[$row['category_name']])) {
            $featured[$row['category_name']] = $row;
        }
    }
    $featured = array_slice(array_values($featured), 0, 3);

    $dishCount = (int) getPDO()
        ->query('SELECT COUNT(*) FROM menu_items WHERE is_available = 1')
        ->fetchColumn();

    // The dish on the big plate in the hero, named in content.php.
    if (!empty($site['hero_dish'])) {
        $pick = getPDO()->prepare(
            'SELECT id, name, description, price, image, image_alt
             FROM menu_items
             WHERE name = ? AND is_available = 1
             LIMIT 1'
        );
        $pick->execute([$site['hero_dish']]);
        $heroDish = $pick->fetch() ?: null;
    }
} catch (Throwable $ex) {
    // The landing page must still open if the database is down — it just
    // shows no dishes. Only the menu page truly needs the database.
    error_log($ex->getMessage());
}

// The map block was added to content.php. Guard it anyway, so an older
// content.php without it cannot break the page.
$map     = $site['map'] ?? null;
$mapLat  = $map ? (float) $map['lat'] : null;
$mapLng  = $map ? (float) $map['lng'] : null;
$mapZoom = $map ? (int) ($map['zoom'] ?? 16) : 16;

// OpenStreetMap wants a small box around the point, not a centre and a zoom.
// A smaller span = a closer look, so the span shrinks as the zoom goes up.
$span = 0.01 / max(1, 2 ** ($mapZoom - 14));
$mapSrc = $map ? sprintf(
    'https://www.openstreetmap.org/export/embed.html?bbox=%F,%F,%F,%F&layer=mapnik&marker=%F,%F',
    $mapLng - $span, $mapLat - $span / 2,
    $mapLng + $span, $mapLat + $span / 2,
    $mapLat, $mapLng
) : null;

// "Open this in a real map app" — works on a phone and on a desktop.
$mapLink = $map
    ? sprintf('https://www.openstreetmap.org/?mlat=%F&mlon=%F#map=%d/%F/%F',
              $mapLat, $mapLng, $mapZoom, $mapLat, $mapLng)
    : null;

require __DIR__ . '/../src/header.php';
?>

<!-- ============ HERO ============ -->
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
            $photo    = trim((string) ($dish['image'] ?? ''));
            $hasPhoto = $photo !== '' && is_file(__DIR__ . '/images/' . basename($photo));
          ?>
          <li class="picks__item">
            <a class="picks__link" href="menu.php#item-<?= (int) $dish['id'] ?>">
              <?php if ($hasPhoto): ?>
                <img class="picks__thumb" src="images/<?= e(basename($photo)) ?>"
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
          // the dish named in content.php, or the cheapest one if that name
          // no longer matches anything on the menu
          $lead = $heroDish ?? ($featured[0] ?? null);
        ?>
        <?php
          $leadPhoto = $lead ? trim((string) ($lead['image'] ?? '')) : '';
          $leadHas   = $leadPhoto !== '' && is_file(__DIR__ . '/images/' . basename($leadPhoto));
        ?>
        <span class="plate-art__disc">
          <?php if ($leadHas): ?>
            <img src="images/<?= e(basename($leadPhoto)) ?>" alt="<?= e($lead['name']) ?>"
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
<section class="section strip-section">
  <div class="wrap">
    <ul class="strip">

      <li class="strip__card">
        <span class="strip__num" aria-hidden="true">01</span>
        <h2 class="strip__title">Read the menu</h2>
        <p class="strip__text">Every dish, by course.</p>
        <a class="strip__go" href="menu.php">The menu<span aria-hidden="true">&rarr;</span></a>
      </li>

      <li class="strip__card">
        <span class="strip__num" aria-hidden="true">02</span>
        <h2 class="strip__title">Order food</h2>
        <p class="strip__text">
          Collect, or delivery over KSh <?= number_format((float) $site['ordering']['delivery_minimum'], 0) ?>.
        </p>
        <a class="strip__go" href="order.php">Start an order<span aria-hidden="true">&rarr;</span></a>
      </li>

      <li class="strip__card">
        <span class="strip__num" aria-hidden="true">03</span>
        <h2 class="strip__title">Book a table</h2>
        <p class="strip__text">See which tables are free.</p>
        <a class="strip__go" href="reserve.php">Reserve<span aria-hidden="true">&rarr;</span></a>
      </li>

    </ul>
  </div>
</section>

<!-- ============ ABOUT US ============ -->
<!-- The story on the left, the map card on the right in its own column.
     The left is laid out as a trail: the lede, then the three things that
     matter numbered down a teal line. -->
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
<section id="hours" class="section hours">
  <div class="wrap">

    <header class="hours__head">
      <p class="hero__kicker">Plan your visit</p>
      <h2 class="hours__title">Opening hours</h2>
    </header>

    <ul class="hours__grid">
      <?php foreach ($site['hours'] as $days => $time): ?>
        <?php
          // "7:00 am - 9:00 pm" splits into the two halves the card shows.
          $parts = preg_split('/\s*[-–—]\s*/u', (string) $time, 2);
          $opens  = trim($parts[0] ?? (string) $time);
          $closes = trim($parts[1] ?? '');
        ?>
        <li class="hours__card">
          <p class="hours__days"><?= e($days) ?></p>

          <?php if ($closes !== ''): ?>
            <div class="hours__span">
              <span class="hours__slot">
                <span class="hours__label">Opens</span>
                <span class="hours__time"><?= e($opens) ?></span>
              </span>
              <span class="hours__dash" aria-hidden="true"></span>
              <span class="hours__slot">
                <span class="hours__label">Closes</span>
                <span class="hours__time"><?= e($closes) ?></span>
              </span>
            </div>
          <?php else: ?>
            <p class="hours__time hours__time--single"><?= e($opens) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- where to find us, under the hours -->
    <ul class="hours__facts">
      <li class="hours__fact">
        <span class="hours__fact-label">Find us</span>
        <span class="hours__fact-value"><?= e($site['address']) ?></span>
      </li>
      <li class="hours__fact">
        <span class="hours__fact-label">Call us</span>
        <a class="hours__fact-value" href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>"><?= e($site['phone']) ?></a>
      </li>
      <li class="hours__fact">
        <span class="hours__fact-label">Kitchen needs</span>
        <span class="hours__fact-value"><?= (int) $site['ordering']['lead_minutes'] ?> minutes&rsquo; notice</span>
      </li>
    </ul>

  </div>
</section>

<?php require __DIR__ . '/../src/footer.php'; ?>
