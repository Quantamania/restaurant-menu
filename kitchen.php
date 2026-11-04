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

if ($signedIn && in_array($_POST['action'] ?? null, ['move', 'paid', 'delete'], true)) {

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'That form had expired. Nothing was changed — please try again.';
    } else {
        $reference = strtoupper(trim((string) ($_POST['reference'] ?? '')));

        if ($_POST['action'] === 'delete') {
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

$scope  = in_array($_GET['scope'] ?? '', ['open', 'today', 'all'], true)
        ? $_GET['scope'] : 'open';

// The bookings view has its own scopes.
$bScope = in_array($_GET['bscope'] ?? '', ['upcoming', 'today', 'all'], true)
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

  <!-- ====================== sign in ====================== -->
  <main class="kgate">
    <div class="kgate__card">
      <!-- The same lockup as every other page. Even the locked door should
           look like it belongs to this restaurant. -->
      <div class="kgate__brand"><?= brand_lockup($site['name'], 'brand--kitchen', 'gate') ?></div>

      <h1 class="kgate__title">Kitchen</h1>

      <?php if (!staff_is_configured()): ?>

        <p class="kgate__lead">This screen has not been set up yet.</p>

        <?php if (staff_request_is_local()): ?>
          <!-- Only on a development machine. On a real host the fix is not
               the visitor's business, and naming the file would be telling
               an attacker exactly what to go looking for. -->
          <ol class="kgate__steps">
            <li>Copy the template:<br><code>cp includes/config.example.php includes/config.php</code></li>
            <li>Make a password:<br><code>php tools/staff-password.php</code></li>
            <li>Paste the line it prints into the <code>staff</code> block of
                <code>includes/config.php</code>, then reload this page.</li>
          </ol>
        <?php else: ?>
          <p class="kgate__lead">Ask whoever set up the site.</p>
        <?php endif; ?>

      <?php elseif (staff_is_locked_out()): ?>

        <p class="msg msg--bad">
          <span class="msg__icon" aria-hidden="true">!</span>
          <span>Too many wrong tries from this device.
            Try again in <?= staff_lock_minutes_left() ?> minutes.</span>
        </p>

      <?php else: ?>

        <p class="kgate__lead">Staff only.</p>

        <?php if ($error): ?>
          <p class="msg msg--bad">
            <span class="msg__icon" aria-hidden="true">!</span>
            <span><?= e($error) ?></span>
          </p>
        <?php endif; ?>

        <form method="post" action="kitchen.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

          <div class="field">
            <label class="field__label" for="password">Password</label>
            <input class="field__input" type="password" id="password" name="password"
                   required autofocus autocomplete="current-password">
          </div>

          <button class="button button--block" type="submit" name="action" value="sign-in">
            Sign in
          </button>
        </form>

      <?php endif; ?>
    </div>
  </main>

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
      <li class="ktally__item ktally__item--new">
        <span class="ktally__n"><?= $counts['new'] ?></span>
        <span class="ktally__k">Waiting</span>
      </li>
      <li class="ktally__item ktally__item--cooking">
        <span class="ktally__n"><?= $counts['preparing'] ?></span>
        <span class="ktally__k">Cooking</span>
      </li>
      <li class="ktally__item ktally__item--ready">
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

      <nav class="kscope" aria-label="How much to show">
        <?php if ($view === 'bookings'): ?>
          <?php foreach (['upcoming' => 'Upcoming', 'today' => 'Today', 'all' => 'All'] as $key => $label): ?>
            <a class="kscope__link<?= $bScope === $key ? ' is-on' : '' ?>"
               href="kitchen.php?view=bookings&amp;bscope=<?= $key ?>"
               <?= $bScope === $key ? 'aria-current="true"' : '' ?>><?= $label ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach (['open' => 'Open', 'today' => 'Today', 'all' => 'Recent'] as $key => $label): ?>
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

      <!-- A dark panel, so bookings read as a different kind of thing from
           the orders board. The cards, the pale-teal labels and the
           label/value split with a rule between are the home page's
           opening-hours block, reused. -->
      <section class="bpanel">
        <header class="bpanel__head">
          <h2 class="bpanel__title">
            Table <span class="bpanel__title-em">bookings</span>
          </h2>
          <p class="bpanel__count">
            <?= $bookingsDue ?> upcoming &middot;
            <?= count($bookings) ?> shown
          </p>
        </header>

        <?php if (!$bookings): ?>
          <p class="bpanel__empty">
            <?= $bScope === 'upcoming'
                ? 'Nothing booked yet. New bookings land here the moment they are made.'
                : 'No bookings to show.' ?>
          </p>
        <?php else: ?>

          <?php
            $lastDay = null;
            foreach ($bookings as $b):
              $when = DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s', $b['reservation_date'] . ' ' . $b['reservation_time']);
              $day      = $when ? $when->format('Y-m-d') : '';
              $isToday  = $day === $now->format('Y-m-d');
              $isPast   = $when && $when < $now;
              $minsAway = $when ? (int) round(($when->getTimestamp() - $now->getTimestamp()) / 60) : null;
              $soon     = $minsAway !== null && $minsAway >= 0 && $minsAway <= 90;
              $party    = (int) ($b['party_size'] ?? 0);
              $seats    = (int) ($b['table_seats'] ?? 0);
          ?>
            <?php if ($day !== $lastDay): ?>
              <?php if ($lastDay !== null): ?></ul><?php endif; ?>
              <?php $lastDay = $day; ?>
              <h3 class="bday">
                <span class="bday__name"><?= $isToday ? 'Today' : e($when ? $when->format('l') : '—') ?></span>
                <span class="bday__rule" aria-hidden="true"></span>
                <span class="bday__date"><?= e($when ? $when->format('j M') : '') ?></span>
              </h3>
              <ul class="bgrid">
            <?php endif; ?>

            <li class="bcard<?= $isPast ? ' bcard--past' : ($soon ? ' bcard--soon' : '') ?>">
              <p class="bcard__time">
                <?= e($when ? $when->format('g:i') : '—') ?><span><?= e($when ? $when->format('a') : '') ?></span>
              </p>

              <p class="bcard__name"><?= e($b['name']) ?></p>

              <!-- Table and party, split by a rule: the home page's
                   Opens / Closes pair, doing a different job. -->
              <div class="bcard__span">
                <span class="bcard__slot">
                  <span class="bcard__label">Table</span>
                  <span class="bcard__value"><?= e($b['table_label'] ?? '—') ?></span>
                  <?php if (!empty($b['table_area'])): ?>
                    <span class="bcard__sub"><?= e($b['table_area']) ?></span>
                  <?php endif; ?>
                </span>
                <span class="bcard__dash" aria-hidden="true"></span>
                <span class="bcard__slot">
                  <span class="bcard__label">Party</span>
                  <span class="bcard__value"><?= $party ?: '—' ?></span>
                  <?php if ($seats > 0): ?>
                    <span class="bcard__sub">of <?= $seats ?> seats</span>
                  <?php endif; ?>
                </span>
              </div>

              <p class="bcard__contact">
                <?php if (!empty($b['phone'])): ?>
                  <a href="tel:<?= e(preg_replace('/\s+/', '', $b['phone'])) ?>"><?= e($b['phone']) ?></a>
                <?php endif; ?>
                <a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a>
              </p>

              <?php if (!empty($b['message'])): ?>
                <p class="bcard__note"><?= nl2br(e($b['message'])) ?></p>
              <?php endif; ?>

              <?php if ($isPast): ?>
                <span class="bcard__chip bcard__chip--past">Passed</span>
              <?php elseif ($soon): ?>
                <span class="bcard__chip bcard__chip--soon">in <?= $minsAway ?> min</span>
              <?php endif; ?>
            </li>

          <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

        <?php elseif (!$orders): ?>

      <p class="kempty">
        <?= $scope === 'open'
            ? 'Nothing waiting. Every order is finished.'
            : 'No orders to show.' ?>
      </p>

    <?php else: ?>

      <div class="kboard">
        <?php foreach ($orders as $order): ?>
          <?php
            $due      = DateTimeImmutable::createFromFormat(
                            'Y-m-d H:i:s', $order['order_date'] . ' ' . $order['order_time']);
            $delivery = $order['order_type'] === 'delivery';
            $paid     = $order['payment_status'] === 'paid';
            $open     = in_array($order['status'], ['new', 'preparing', 'ready'], true);

            // Late, or due inside the hour.
            $minsLeft = $due ? (int) round(($due->getTimestamp() - $now->getTimestamp()) / 60) : null;
            $urgency  = 'later';

            if ($open && $minsLeft !== null) {
                if ($minsLeft < 0)       { $urgency = 'late'; }
                elseif ($minsLeft <= 20) { $urgency = 'now'; }
                elseif ($minsLeft <= 60) { $urgency = 'soon'; }
            }

            if ($minsLeft === null)   { $whenWords = '—'; }
            elseif ($minsLeft < -60)  { $whenWords = abs(intdiv($minsLeft, 60)) . 'h late'; }
            elseif ($minsLeft < 0)    { $whenWords = abs($minsLeft) . ' min late'; }
            elseif ($minsLeft < 60)   { $whenWords = 'in ' . $minsLeft . ' min'; }
            else                      { $whenWords = 'in ' . round($minsLeft / 60) . 'h'; }
          ?>

          <article class="kticket kticket--<?= e($order['status']) ?> kticket--<?= $urgency ?>">

            <!-- One strip across the top: who it is, where it has got to, and
                 when it is due. On a full-width ticket all three fit on one
                 line, which is what makes a stack of them scannable. -->
            <header class="kticket__head">
              <span class="kticket__ref"><?= e($order['reference']) ?></span>
              <span class="kticket__state"><?= e($statusWords[$order['status']] ?? $order['status']) ?></span>

              <span class="kticket__when">
                <strong><?= e($due ? $due->format('g:i a') : '—') ?></strong>
                <?php if ($due && $due->format('Y-m-d') !== $now->format('Y-m-d')): ?>
                  <span class="kticket__day"><?= e($due->format('D j M')) ?></span>
                <?php endif; ?>
                <span class="kticket__rel"><?= e($whenWords) ?></span>
              </span>
            </header>

            <div class="kticket__body">

              <!-- The dishes, biggest thing on the ticket: it is what the
                   cook is actually reading. -->
              <div class="kticket__main">
                <ul class="kticket__items">
                  <?php foreach ($order['items'] as $line): ?>
                    <?php
                      $photo = trim((string) ($line['image'] ?? ''));
                      $has   = $photo !== '' && is_file(__DIR__ . '/images/' . basename($photo));
                    ?>
                    <li>
                      <!-- The photo, so a cook recognises the dish without
                           reading. The name stays: the photo is a shortcut,
                           not a replacement. -->
                      <?php if ($has): ?>
                        <img class="kticket__thumb" src="images/<?= e(basename($photo)) ?>"
                             alt="" loading="lazy" width="48" height="48">
                      <?php else: ?>
                        <span class="kticket__thumb kticket__thumb--none" aria-hidden="true">
                          <?= e(mb_strtoupper(mb_substr($line['name'], 0, 1))) ?>
                        </span>
                      <?php endif; ?>
                      <span class="kticket__qty"><?= (int) $line['quantity'] ?>&times;</span>
                      <span class="kticket__dish"><?= e($line['name']) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>

                <?php if (!empty($order['notes'])): ?>
                  <p class="kticket__notes">
                    <span class="kticket__notes-k">Note</span>
                    <?= nl2br(e($order['notes'])) ?>
                  </p>
                <?php endif; ?>
              </div>

              <!-- Everything the cook does not need while cooking, but the
                   counter does when handing it over. -->
              <div class="kticket__side">
                <dl class="kticket__who">
                  <dt><?= $delivery ? 'Deliver to' : 'Collection' ?></dt>
                  <dd>
                    <span class="kticket__name">
                      <?= e($order['name']) ?>
                      <?php if (!empty($order['account_email'])): ?>
                        <!-- Same order, kitchen side: says it belongs to an
                             account, so the customer can be told "it is on
                             your orders page" rather than hunting a code. -->
                        <span class="kacct" title="Registered account: <?= e($order['account_email']) ?>">Account</span>
                      <?php else: ?>
                        <span class="kacct kacct--guest" title="Placed before accounts existed">Guest</span>
                      <?php endif; ?>
                    </span>
                    <a class="kticket__tel" href="tel:<?= e(preg_replace('/\s+/', '', $order['phone'])) ?>">
                      <?= e($order['phone']) ?>
                    </a>
                    <?php if ($delivery && !empty($order['address'])): ?>
                      <span class="kticket__addr"><?= nl2br(e($order['address'])) ?></span>
                    <?php endif; ?>
                  </dd>
                </dl>

                <p class="kticket__money">
                  <span class="kticket__total">KSh <?= number_format((float) $order['total'], 0) ?></span>
                  <span class="kpay <?= $paid ? 'kpay--paid' : 'kpay--owing' ?>">
                    <?php if ($paid): ?>
                      Paid<?= $order['payment_method'] === 'mpesa' ? ' · M-Pesa' : '' ?>
                    <?php elseif ($order['payment_status'] === 'pending'): ?>
                      M-Pesa pending
                    <?php else: ?>
                      Unpaid
                    <?php endif; ?>
                  </span>
                </p>
              </div>

            </div>

            <!-- One form per action. Each carries the reference and the
                 current scope, so the redirect afterwards comes back to the
                 same view the cook was looking at. -->
            <footer class="kticket__acts">
              <?php foreach (order_next_statuses($order['status']) as $to): ?>
                <form method="post" action="kitchen.php">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="reference" value="<?= e($order['reference']) ?>">
                  <input type="hidden" name="scope" value="<?= e($scope) ?>">
                  <input type="hidden" name="to" value="<?= e($to) ?>">
                  <button class="kbtn <?= $to === 'cancelled' ? 'kbtn--off' : 'kbtn--go' ?>"
                          type="submit" name="action" value="move">
                    <?= e(order_action_label($to, $order['order_type'])) ?>
                  </button>
                </form>
              <?php endforeach; ?>

              <?php if (!$paid && $order['status'] !== 'cancelled'): ?>
                <form method="post" action="kitchen.php">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="reference" value="<?= e($order['reference']) ?>">
                  <input type="hidden" name="scope" value="<?= e($scope) ?>">
                  <button class="kbtn kbtn--cash" type="submit" name="action" value="paid">
                    Took payment
                  </button>
                </form>
              <?php endif; ?>

              <?php if ($order['status'] === 'cancelled'): ?>
                <?php if ($confirmDelete === $order['reference']): ?>

                  <!-- Second press. The question is asked in the row itself,
                       naming the order, so there is no chance of confirming
                       the wrong one. -->
                  <div class="kconfirm">
                    <p class="kconfirm__ask">
                      Delete <strong><?= e($order['reference']) ?></strong> for good?
                      Its dishes, payment attempts and history go with it.
                      This cannot be undone.
                    </p>
                    <div class="kconfirm__acts">
                      <form method="post" action="kitchen.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="reference" value="<?= e($order['reference']) ?>">
                        <input type="hidden" name="scope" value="<?= e($scope) ?>">
                        <button class="kbtn kbtn--danger" type="submit" name="action" value="delete">
                          Yes, delete it
                        </button>
                      </form>
                      <a class="kbtn kbtn--off"
                         href="kitchen.php?scope=<?= e($scope) ?>">Keep it</a>
                    </div>
                  </div>

                <?php elseif ($paid): ?>
                  <!-- Paid and cancelled: somebody is owed a refund, and
                       deleting would destroy the only record of the money. -->
                  <p class="kticket__closed">
                    Cancelled, but paid — refund this before clearing it.
                  </p>
                <?php else: ?>
                  <a class="kbtn kbtn--off"
                     href="kitchen.php?scope=<?= e($scope) ?>&amp;confirm=<?= urlencode($order['reference']) ?>">
                    Delete
                  </a>
                <?php endif; ?>
              <?php elseif (!order_next_statuses($order['status']) && $paid): ?>
                <p class="kticket__closed">Nothing left to do.</p>
              <?php endif; ?>

              <?php $history = order_history((int) $order['id']); ?>
              <?php if (count($history) > 1): ?>
                <!-- A plain <details>, so the history is there when somebody
                     asks "when did that go out?" without taking room on the
                     board the rest of the time. No script needed. It sits at
                     the end of the action row so it never pushes the buttons
                     around when it is opened. -->
                <details class="khist">
                  <summary>History</summary>
                  <ol class="khist__list">
                    <?php foreach ($history as $event): ?>
                      <li>
                        <time><?= e(date('g:i a', strtotime($event['created_at']))) ?></time>
                        <?= e($statusWords[$event['to_status']] ?? $event['to_status']) ?>
                        <span class="khist__who"><?= e($event['actor']) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                </details>
              <?php endif; ?>
            </footer>

          </article>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>

    <p class="kfoot">
      <?php if ($scope === 'open'): ?>
        This board refreshes itself every 45 seconds.
      <?php else: ?>
        <a href="kitchen.php?scope=open">Back to the live board</a>
      <?php endif; ?>
    </p>

  </main>

<?php endif; ?>

</body>
</html>
