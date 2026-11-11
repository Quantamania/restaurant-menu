<?php
/* LANDING PAGE */

require __DIR__ . '/includes/setup.php';

$pageTitle = 'Home';
$featured  = [];
$dishCount = 0;
$heroDish  = null;

try {
    // One dish from each course, so the three chips show the range of the menu.
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
        // the rows arrive cheapest-first within each course.
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
    // The landing page must still open if the database is down — it just shows no dishes.
    error_log($ex->getMessage());
}

// The map block was added to content.php.
$map     = $site['map'] ?? null;
$mapLat  = $map ? (float) $map['lat'] : null;
$mapLng  = $map ? (float) $map['lng'] : null;
$mapZoom = $map ? (int) ($map['zoom'] ?? 16) : 16;

// OpenStreetMap wants a small box around the point, not a centre and a zoom.
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

require __DIR__ . '/includes/header.php';
?>

<!-- ============ HERO ============ -->
<?php require __DIR__ . '/includes/sections/index/hero.php'; ?>
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
<?php require __DIR__ . '/includes/sections/index/about.php'; ?>
<?php require __DIR__ . '/includes/sections/index/hours.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
