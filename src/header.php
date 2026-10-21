<?php
/* The top of every page: the <head>, the sidebar that carries the navigation */
?>
<?php
  // The landing page wears its navigation across the top.
  $isLanding = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') === 'index.php';
?>
<!DOCTYPE html>
<html lang="en"<?= $isLanding ? ' class="landing"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($pageTitle ?? 'Home') . ' | ' . $site['name']) ?></title>
  <meta name="description" content="<?= e($site['tagline']) ?> <?= e($site['name']) ?>, <?= e($site['address']) ?>.">

  <!-- The tab icon. The SVG is what modern browsers use and it stays sharp at
       any size; the .ico is the fallback for older ones, and the PNG is what
       iOS uses when the site is pinned to a home screen. -->
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="icon" href="favicon.ico" sizes="48x48">
  <link rel="apple-touch-icon" href="apple-touch-icon.png">
  <meta name="theme-color" content="#1c140e">

  <?php if (!empty($metaRefresh)): ?>
    <!-- Set by a page that is watching something change (an order being
         cooked). No script, so it keeps working on a phone with the tab in
         the background. -->
    <meta http-equiv="refresh" content="<?= (int) $metaRefresh ?>">
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@700;800&family=Roboto:wght@300;400;500;700;800;900&display=swap">
  <link rel="stylesheet" href="style.css">

  <script>
    /* Read the hand-off left by the page you came from and mark this document before anyth */
    (function () {
      try {
        var how = sessionStorage.getItem("nav-morph");
        sessionStorage.removeItem("nav-morph");
        if (how === "in" || how === "out" || how === "fade") {
          if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches &&
              window.matchMedia("(min-width: 961px)").matches) {
            document.documentElement.className += " nav-" + how;
          }
        }
      } catch (e) {}
    })();
  </script>
</head>
<body class="<?= $isLanding ? 'is-landing' : 'is-inner' ?>">

<a class="skip-link" href="#main">Skip to content</a>

<!-- On a narrow screen the sidebar slides away and this bar takes over. -->
<header class="topbar">
  <button class="topbar__toggle" type="button"
          aria-expanded="false" aria-controls="sidebar" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
  <a class="topbar__brand" href="index.php"><?= wordmark($site['name']) ?></a>
</header>

<?php
  // Which page are we on? Used to mark the current item in the navigation.
  $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

  // href, short label, icon, the page it belongs to, and whether it is the item that.
  $navItems = [
      ['index.php',        'Home',    'home',     'index.php',        true],
      ['menu.php',         'Menu',    'grid',     'menu.php',         true],
      ['index.php#about',  'About',   'info',     'index.php',        false],
      ['order.php',        'Order',   'bag',      'order.php',        true],
      ['order-status.php', 'Track',   'truck',    'order-status.php', true],
      ['reserve.php',      'Reserve', 'calendar', 'reserve.php',      true],
      // Last, and its label changes with who is reading it.
      ['account.php', logged_in() ? 'Account' : 'Sign in', 'user', 'account.php', true],
  ];

  // Small inline icons — no image files to ship or go missing.
  $icons = [
      'home'     => '<path d="M4 11.5 12 4l8 7.5"/><path d="M6 10v10h12V10"/><path d="M10 20v-6h4v6"/>',
      'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
      'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
      'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'bag'      => '<path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
      'truck'    => '<rect x="2" y="7" width="12" height="9" rx="1.5"/><path d="M14 10h4l3 3v3h-7Z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
      'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
      'user'     => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/>',
  ];
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar__inner">

    <!-- The box logo, with a bite masked out of the top corner. The markup
         lives in brand_lockup() so the kitchen screen shows the same mark
         without a second copy of it drifting out of step. -->
    <?= brand_lockup($site['name']) ?>

    <!-- The dark panel starts below the logo and stops short of the bottom,
         so it curves away from the page edge at both ends. -->
    <div class="sidebar__panel">
    <nav class="nav" aria-label="Main">
      <ul class="nav__list">
        <?php foreach ($navItems as [$href, $label, $icon, $page, $marks]): ?>
          <?php $isHere = $marks && $page === $currentPage; ?>
          <li>
            <a class="nav__link<?= $isHere ? ' is-here' : '' ?>"
               href="<?= e($href) ?>"
               <?= $isHere ? 'aria-current="page"' : '' ?>>
              <span class="nav__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <?= $icons[$icon] ?>
                </svg>
              </span>
              <span class="nav__text"><?= e($label) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="sidebar__foot">
      <a class="sidebar__dot" href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>"
         title="<?= e($site['phone']) ?>" aria-label="Call <?= e($site['phone']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2.2 2A16 16 0 0 1 3 6.2 2 2 0 0 1 5 4Z"/>
        </svg>
      </a>
      <a class="sidebar__dot" href="index.php#hours" title="Opening hours" aria-label="Opening hours">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
        </svg>
      </a>
    </div>

    </div><!-- /.sidebar__panel -->

  </div>
</aside>

<!-- Pulls the rail in and out on a wide screen. -->
<button class="rail-toggle" type="button" data-rail-toggle
        aria-controls="sidebar" aria-expanded="true" aria-label="Hide the menu">
  <svg class="rail-toggle__in" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M15 5 8 12l7 7"/>
  </svg>
  <svg class="rail-toggle__out" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M9 5l7 7-7 7"/>
  </svg>
</button>

<!-- Tapping the dimmed area closes the sidebar on a phone. -->
<div class="scrim" data-scrim hidden></div>

<!-- Covers the page (but not the navigation) while one document is replaced
     by the next, so the colour change never shows as a flash. -->
<div class="pagefade" aria-hidden="true"></div>
<div class="shell">
<main id="main">
<?php
  // The trail of where you are.
  if (!empty($crumbs)) {
      echo breadcrumbs($crumbs);
  }
?>
