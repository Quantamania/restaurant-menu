<?php
/* KITCHEN SCREEN */

require __DIR__ . '/includes/setup.php';

$pageTitle = 'Kitchen';
$error     = null;
$done      = null;

// Not indexed, not previewed, not stored by a shared browser cache.
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');

/* ------------------------------------------------------------- signing in */

if (($_POST['action'] ?? null) === 'sign-in') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'That form had expired. Please try again.';
    } else {
        $result = staff_login((string) ($_POST['password'] ?? ''));

        if ($result['ok']) {
            // Redirect, so the password is not sitting in a form that a refresh would re-submit.
            header('Location: kitchen.php');
            exit;
        }

        $error = $result['error'];
    }
}

if (($_POST['action'] ?? null) === 'sign-out') {
    if (csrf_valid($_POST['csrf'] ?? null)) {
        staff_logout();
        header('Location: kitchen.php');
        exit;
    }
}

$signedIn = staff_logged_in();

/* ------------------------------------------------------- moving an order */

if ($signedIn && in_array($_POST['action'] ?? null,
                          ['move', 'paid', 'delete', 'delete-booking'], true)) {

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'That form had expired. Nothing was changed — please try again.';
    } else {
        $reference = strtoupper(trim((string) ($_POST['reference'] ?? '')));

        if ($_POST['action'] === 'delete-booking') {
            /* Clearing a finished or cancelled booking off the board. The
             * function refuses anything still to come. */
            $result = delete_reservation((int) ($_POST['reservation_id'] ?? 0), 'kitchen');
            $done   = $result['ok'] ? 'Booking removed.' : null;

            if (!$result['ok']) {
                $error = $result['error'];
            } else {
                header('Location: kitchen.php?view=bookings&bscope='
                     . urlencode((string) ($_POST['bscope'] ?? 'upcoming'))
                     . '&done=' . urlencode($done));
                exit;
            }
        } elseif ($_POST['action'] === 'delete') {
            $result = delete_order($reference);
            $done   = $result['ok'] ? $reference . ' deleted.' : null;
        } elseif ($_POST['action'] === 'paid') {
            $result = mark_order_paid($reference);
            $done   = $result['ok'] ? 'Marked ' . $reference . ' as paid.' : null;
        } else {
            $to = (string) ($_POST['to'] ?? '');

            // Checked against the known statuses before it goes any further.
            if (!in_array($to, ['preparing', 'ready', 'completed', 'cancelled'], true)) {
                $result = ['ok' => false, 'error' => 'That is not a status an order can be in.'];
            } else {
                $result = move_order($reference, $to);
            }

            $done = $result['ok']
                ? $reference . ' is now ' . str_replace(
                    ['new', 'preparing', 'ready', 'completed', 'cancelled'],
                    ['received', 'being cooked', 'ready', 'finished', 'cancelled'],
                    $to) . '.'
                : null;
        }

        if (!$result['ok']) {
            $error = $result['error'];
        }

        // Redirect after a successful change (POST-redirect-GET).
        if ($result['ok']) {
            header('Location: kitchen.php?scope=' . urlencode((string) ($_POST['scope'] ?? 'open'))
                 . '&done=' . urlencode((string) $done));
            exit;
        }
    }
}

if (isset($_GET['done']) && $done === null) {
    // Trimmed and escaped on output; this only ever appears inside e().
    $done = mb_substr((string) $_GET['done'], 0, 120);
}

/* ----------------------------------------------------------- the board */

// Two views on one screen: the board, and the bookings.
$view = ($_GET['view'] ?? '') === 'bookings' ? 'bookings' : 'orders';

$scope  = in_array($_GET['scope'] ?? '', ['open', 'today'], true)
        ? $_GET['scope'] : 'open';

// The bookings view has its own scopes.
$bScope = in_array($_GET['bscope'] ?? '', ['upcoming', 'today'], true)
        ? $_GET['bscope'] : 'upcoming';

// Deleting is irreversible, so it takes two deliberate presses.
$confirmDelete = strtoupper(trim((string) ($_GET['confirm'] ?? '')));
$orders = [];

$bookings      = [];
$bookingsDue   = 0;

if ($signedIn) {
    try {
        if ($view === 'bookings') {
            $bookings = kitchen_reservations($bScope);
        } else {
            $orders = kitchen_orders($scope);

        }
        $bookingsDue = upcoming_reservation_count();
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        $error = 'Cannot reach the database right now.';
    }
}

// How the kitchen reads each status.
$statusWords = [
    'new'       => 'Waiting',
    'preparing' => 'Cooking',
    'ready'     => 'Ready',
    'completed' => 'Finished',
    'cancelled' => 'Cancelled',
];

$counts = ['new' => 0, 'preparing' => 0, 'ready' => 0];
foreach ($orders as $o) {
    if (isset($counts[$o['status']])) {
        $counts[$o['status']]++;
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
  <title><?= $signedIn ? ($counts['new'] + $counts['preparing']) . ' to cook — ' : '' ?>Kitchen</title>

  <?php if ($signedIn && $scope === 'open'): ?>
    <!-- Keeps a tablet on a shelf current without any script. 45 seconds is
         short enough that a new order is noticed and long enough that it is
         not fighting somebody pressing a button. Only on the live board:
         refreshing the sign-in page would wipe what was half-typed. -->
    <meta http-equiv="refresh" content="45">
  <?php endif; ?>

  <link rel="icon" href="favicon.svg" type="image/svg+xml">

  <!-- The same faces as every other page. Without these the logo falls back
       to a system sans: the wordmark loses Poppins 700 and reads thin, and
       the M in the box loses Anton entirely. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@700;800&family=Roboto:wght@300;400;500;700;800;900&display=swap">

  <link rel="stylesheet" href="css/style.css">
</head>
<body class="kitchen<?= $signedIn && $view === 'bookings' ? ' kitchen--bookings' : '' ?>">

<?php if (!$signedIn): ?>
  <?php require __DIR__ . '/includes/sections/kitchen/staff-door.php'; ?>

<?php else: ?>

  <!-- ====================== the board ====================== -->
  <header class="kbar">
    <div class="kbar__left">
      <!-- The full logo, same as the customer pages. It doubles as the way
           back out to the site, which is the only link a cook needs here. -->
      <?= brand_lockup($site['name'], 'brand--kitchen', 'bar') ?>
      <div>
        <h1 class="kbar__title">Kitchen</h1>
        <p class="kbar__clock"><?= e($now->format('D j M, g:i a')) ?></p>
      </div>
    </div>

    <!-- Three numbers, big enough to read from across the room. -->
    <?php if ($view === 'orders'): ?>
    <ul class="ktally">
      <li class="ktally__item ktally__item--new<?= $counts['new'] === 0 ? ' ktally__item--zero' : '' ?>">
        <span class="ktally__n"><?= $counts['new'] ?></span>
        <span class="ktally__k">Waiting</span>
      </li>
      <li class="ktally__item ktally__item--cooking<?= $counts['preparing'] === 0 ? ' ktally__item--zero' : '' ?>">
        <span class="ktally__n"><?= $counts['preparing'] ?></span>
        <span class="ktally__k">Cooking</span>
      </li>
      <li class="ktally__item ktally__item--ready<?= $counts['ready'] === 0 ? ' ktally__item--zero' : '' ?>">
        <span class="ktally__n"><?= $counts['ready'] ?></span>
        <span class="ktally__k">Ready</span>
      </li>
    </ul>
    <?php endif; ?>

    <div class="kbar__right">
      <!-- Orders or bookings. The booking tab carries its own count, so a
           cook sees there is something coming without opening it. -->
      <nav class="kviews" aria-label="Orders or bookings">
        <a class="kview<?= $view === 'orders' ? ' is-on' : '' ?>"
           href="kitchen.php?scope=<?= e($scope) ?>"
           <?= $view === 'orders' ? 'aria-current="page"' : '' ?>>Orders</a>
        <a class="kview<?= $view === 'bookings' ? ' is-on' : '' ?>"
           href="kitchen.php?view=bookings&amp;bscope=<?= e($bScope) ?>"
           <?= $view === 'bookings' ? 'aria-current="page"' : '' ?>>Bookings<?php
           if ($bookingsDue > 0): ?><span class="kview__n"><?= $bookingsDue ?></span><?php endif; ?></a>
      </nav>

      <?php /* Finished and cancelled work is not on this board — it is one
             * click away, so the board only ever shows what still needs doing. */ ?>
      <a class="button button--small button--ghost"
         href="history.php<?= $view === 'bookings' ? '?view=bookings' : '' ?>">History</a>

      <nav class="kscope" aria-label="How much to show">
        <?php if ($view === 'bookings'): ?>
          <?php foreach (['upcoming' => 'Upcoming', 'today' => 'Today'] as $key => $label): ?>
            <a class="kscope__link<?= $bScope === $key ? ' is-on' : '' ?>"
               href="kitchen.php?view=bookings&amp;bscope=<?= $key ?>"
               <?= $bScope === $key ? 'aria-current="true"' : '' ?>><?= $label ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach (['open' => 'Open', 'today' => 'Today'] as $key => $label): ?>
            <a class="kscope__link<?= $scope === $key ? ' is-on' : '' ?>"
               href="kitchen.php?scope=<?= $key ?>"
               <?= $scope === $key ? 'aria-current="true"' : '' ?>><?= $label ?></a>
          <?php endforeach; ?>
        <?php endif; ?>
      </nav>

      <?php if (staff_auth_required()): ?>
        <form method="post" action="kitchen.php" class="kbar__out">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="linkbtn" type="submit" name="action" value="sign-out">Sign out</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <main class="kmain">

    <?= breadcrumbs([['Home', 'index.php'], ['Kitchen', null]]) ?>

    <?php if ($error): ?>
      <p class="msg msg--bad">
        <span class="msg__icon" aria-hidden="true">!</span>
        <span><?= e($error) ?></span>
      </p>
    <?php elseif ($done): ?>
      <p class="msg msg--ok">
        <span class="msg__icon" aria-hidden="true">&check;</span>
        <span><?= e($done) ?></span>
      </p>
    <?php endif; ?>

    <?php if ($view === 'bookings'): ?>
      <?php require __DIR__ . '/includes/sections/kitchen/bookings.php'; ?>

        <?php elseif (!$orders): ?>

      <p class="kempty">
        <?= $scope === 'open'
            ? 'Nothing waiting. Every order is finished.'
            : 'No orders to show.' ?>
      </p>

    <?php else: ?>

      <?php require __DIR__ . '/includes/sections/kitchen/order-board.php'; ?>

    <?php endif; ?>

    <p class="kfoot">
      <?php if ($scope === 'open'): ?>
        This board refreshes itself every 45 seconds.
      <?php else: ?>
        <a href="kitchen.php?scope=open">Back to the live board</a>
      <?php endif; ?>
    </p>

  </main>

  <?php /* The name across the foot, the same wordmark the home page footer
         * uses. Decoration only — it has been announced already. */ ?>
  <div class="kmark" aria-hidden="true">
    <?= wordmark($site['name']) ?>
  </div>

<?php endif; ?>

</body>
</html>
