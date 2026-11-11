<?php
/* HISTORY — orders and bookings that are done with.
 *
 * The kitchen board only carries live work. Everything finished, collected or
 * cancelled moves here, so a cook looking at kitchen.php sees what still needs
 * doing and nothing else. This is also where the board gets cleared out. */

require __DIR__ . '/includes/setup.php';

$error = null;
$done  = null;

$signedIn = staff_logged_in();

// Orders or bookings, same split as the kitchen.
$view = ($_GET['view'] ?? '') === 'bookings' ? 'bookings' : 'orders';

/* --------------------------------------------------- clearing things out */

if ($signedIn && in_array($_POST['action'] ?? null, ['delete', 'delete-booking'], true)) {

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'That form had expired. Nothing was changed — please try again.';
    } elseif ($_POST['action'] === 'delete-booking') {
        $result = delete_reservation((int) ($_POST['reservation_id'] ?? 0), 'kitchen');

        if ($result['ok']) {
            header('Location: history.php?view=bookings&done=' . urlencode('Booking removed.'));
            exit;
        }
        $error = $result['error'];
    } else {
        $reference = strtoupper(trim((string) ($_POST['reference'] ?? '')));
        $result    = delete_order($reference, 'kitchen');

        if ($result['ok']) {
            header('Location: history.php?done=' . urlencode($reference . ' deleted.'));
            exit;
        }
        $error = $result['error'];
    }
}

if (isset($_GET['done']) && $done === null) {
    $done = mb_substr((string) $_GET['done'], 0, 120);
}

$confirmOrder   = (string) ($_GET['confirm'] ?? '');
$confirmBooking = (int) ($_GET['bconfirm'] ?? 0);

$orders   = [];
$bookings = [];
$exits    = [];

if ($signedIn) {
    try {
        if ($view === 'bookings') {
            $bookings = kitchen_reservations('past');
        } else {
            $orders = kitchen_orders('past');

            /* Orders that were deleted are not in $orders — the row is gone.
             * Their history survives, so this is the only place they can be
             * accounted for. A day's worth, not three hours. */
            $exits = kitchen_recent_exits(60 * 24);
        }
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        $error = 'Cannot reach the database right now.';
    }
}

$now = new DateTimeImmutable();
?>
<!DOCTYPE html>
<html lang="en" class="kitchen-html">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>History — Kitchen</title>

  <link rel="icon" href="favicon.svg" type="image/svg+xml">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@700;800&family=Roboto:wght@300;400;500;700;800;900&display=swap">

  <link rel="stylesheet" href="css/style.css">
</head>
<body class="kitchen<?= $signedIn && $view === 'bookings' ? ' kitchen--bookings' : '' ?>">

<?php if (!$signedIn): ?>

  <main class="kgate">
    <div class="kgate__card">
      <div class="kgate__brand"><?= brand_lockup($site['name'], 'brand--kitchen', 'gate') ?></div>
      <h1 class="kgate__title">History</h1>
      <p class="kgate__lead">Sign in on the kitchen screen first.</p>
      <p><a class="button button--block" href="kitchen.php">Go to the kitchen</a></p>
    </div>
  </main>

<?php else: ?>

  <header class="kbar">
    <div class="kbar__left">
      <?= brand_lockup($site['name'], 'brand--kitchen', 'bar') ?>
      <div>
        <h1 class="kbar__title">History</h1>
        <p class="kbar__clock"><?= e($now->format('D j M, g:i a')) ?></p>
      </div>
    </div>

    <div class="kbar__right">
      <nav class="kviews" aria-label="Orders or bookings">
        <a class="kview<?= $view === 'orders' ? ' is-on' : '' ?>"
           href="history.php"
           <?= $view === 'orders' ? 'aria-current="page"' : '' ?>>Past orders</a>
        <a class="kview<?= $view === 'bookings' ? ' is-on' : '' ?>"
           href="history.php?view=bookings"
           <?= $view === 'bookings' ? 'aria-current="page"' : '' ?>>Past bookings</a>
      </nav>

      <a class="button button--small" href="kitchen.php">Back to the board</a>
    </div>
  </header>

  <main class="kmain">

    <?php if ($error): ?>
      <p class="msg msg--bad"><span class="msg__icon" aria-hidden="true">!</span> <?= e($error) ?></p>
    <?php elseif ($done): ?>
      <p class="msg msg--ok"><span class="msg__icon" aria-hidden="true">&check;</span> <?= e($done) ?></p>
    <?php endif; ?>

    <?php /* ============================ past bookings ============================ */ ?>
    <?php /* What left the board. A deleted order has no row left, so this is
           * the only record of it — reference, what happened, who did it. */ ?>
    <?php if ($view !== 'bookings' && $exits): ?>
    <?php require __DIR__ . '/includes/sections/history/left-the-board.php'; ?>
    <?php endif; ?>


    <?php if ($view === 'bookings'): ?>
    <?php require __DIR__ . '/includes/sections/history/past-bookings.php'; ?>
    <?php elseif (!$orders): ?>

      <p class="kempty">Nothing here yet. Orders move across once they are collected or cancelled.</p>

    <?php else: ?>
    <?php require __DIR__ . '/includes/sections/history/past-orders.php'; ?>
    <?php endif; ?>

  </main>

  <?php /* The name across the foot, the same wordmark the home page footer
         * uses. Decoration only — it has been announced already. */ ?>
  <div class="kmark" aria-hidden="true">
    <?= wordmark($site['name']) ?>
  </div>

<?php endif; ?>

<script src="js/script.js"></script>
</body>
</html>
