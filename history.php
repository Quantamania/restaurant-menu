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

if ($signedIn) {
    try {
        if ($view === 'bookings') {
            $bookings = kitchen_reservations('past');
        } else {
            $orders = kitchen_orders('past');
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
    <?php if ($view === 'bookings'): ?>

      <section class="bpanel">
        <header class="bpanel__head">
          <h2 class="bpanel__title">Past <span class="bpanel__title-em">bookings</span></h2>
          <p class="bpanel__count"><?= count($bookings) ?> shown</p>
        </header>

        <?php if (!$bookings): ?>
          <p class="kempty">Nothing here yet. Bookings move across once the day has passed, or when they are cancelled.</p>
        <?php else: ?>
          <ul class="bgrid">
            <?php foreach ($bookings as $b): ?>
              <?php
                $when      = strtotime($b['reservation_date'] . ' ' . $b['reservation_time']);
                $cancelled = $b['status'] === 'cancelled';
                $seats     = (int) ($b['table_seats'] ?? 0);
              ?>
              <li class="bcard <?= $cancelled ? 'bcard--off' : 'bcard--past' ?>">
                <p class="bcard__time">
                  <?= e(date('g:i', $when)) ?><span><?= e(date('a', $when)) ?></span>
                </p>

                <p class="bcard__name"><?= e($b['name']) ?></p>
                <p class="bcard__contact"><?= e(date('D j M Y', $when)) ?></p>

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
                    <span class="bcard__value"><?= (int) $b['party_size'] ?: '—' ?></span>
                    <?php if ($seats > 0): ?>
                      <span class="bcard__sub">of <?= $seats ?> seats</span>
                    <?php endif; ?>
                  </span>
                </div>

                <span class="bcard__chip <?= $cancelled ? 'bcard__chip--off' : 'bcard__chip--past' ?>">
                  <?= $cancelled ? 'Cancelled' : 'Passed' ?>
                </span>

                <?php if ($confirmBooking === (int) $b['id']): ?>
                  <div class="bcard__confirm">
                    <p>Remove this booking for good?</p>
                    <div class="bcard__confirm-acts">
                      <form method="post" action="history.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="reservation_id" value="<?= (int) $b['id'] ?>">
                        <button class="button button--small button--danger" type="submit"
                                name="action" value="delete-booking">Yes, remove</button>
                      </form>
                      <a class="button button--small button--ghost" href="history.php?view=bookings">Keep</a>
                    </div>
                  </div>
                <?php else: ?>
                  <p class="bcard__clear">
                    <a class="button button--small button--danger"
                       href="history.php?view=bookings&amp;bconfirm=<?= (int) $b['id'] ?>">Remove</a>
                  </p>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    <?php /* ============================= past orders ============================= */ ?>
    <?php elseif (!$orders): ?>

      <p class="kempty">Nothing here yet. Orders move across once they are collected or cancelled.</p>

    <?php else: ?>

      <div class="kboard">
        <?php foreach ($orders as $order): ?>
          <?php
            $due   = DateTimeImmutable::createFromFormat(
                       'Y-m-d H:i:s', $order['order_date'] . ' ' . $order['order_time']);
            $paid  = $order['payment_status'] === 'paid';
            $owing = $order['status'] === 'cancelled' && $paid;
          ?>
          <article class="kticket kticket--<?= e($order['status']) ?>">
            <header class="kticket__head">
              <span class="kticket__ref"><?= e($order['reference']) ?></span>
              <span class="kticket__state"><?= $order['status'] === 'completed' ? 'Finished' : 'Cancelled' ?></span>
              <span class="kpay <?= $paid ? 'kpay--paid' : 'kpay--owing' ?>">
                <?= $paid ? 'Paid' : 'Unpaid' ?>
              </span>
              <span class="kticket__rel"><?= e($due ? $due->format('D j M, g:i a') : '—') ?></span>
            </header>

            <div class="kticket__body">
              <p class="kticket__who">
                <?= e($order['name']) ?>
                <?php if (!empty($order['account_email'])): ?>
                  <span class="kacct">account</span>
                <?php endif; ?>
              </p>

              <ul class="kticket__items">
                <?php foreach ($order['items'] as $line): ?>
                  <?php
                    $photo = trim((string) ($line['image'] ?? ''));
                    $has   = $photo !== '' && is_file(__DIR__ . '/images/' . basename($photo));
                  ?>
                  <li class="kticket__line">
                    <?php if ($has): ?>
                      <img class="kticket__thumb" src="images/<?= e(basename($photo)) ?>"
                           alt="" loading="lazy" width="44" height="44">
                    <?php else: ?>
                      <span class="kticket__thumb kticket__thumb--none">
                        <?= e(mb_strtoupper(mb_substr($line['name'], 0, 1))) ?>
                      </span>
                    <?php endif; ?>
                    <span class="kticket__qty"><?= (int) $line['quantity'] ?>&times;</span>
                    <span class="kticket__dish"><?= e($line['name']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>

              <p class="kticket__total">KSh <?= number_format((float) $order['total'], 0) ?></p>

              <?php if ($owing): ?>
                <p class="kticket__closed">Cancelled after it was paid — refund this before clearing it.</p>
              <?php endif; ?>

              <?php if ($confirmOrder === $order['reference']): ?>
                <div class="kconfirm">
                  <p class="kconfirm__ask">
                    Delete <strong><?= e($order['reference']) ?></strong> for good?
                    Its dishes, payment attempts and history go with it.
                  </p>
                  <div class="kconfirm__acts">
                    <form method="post" action="history.php">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="reference" value="<?= e($order['reference']) ?>">
                      <button class="kbtn kbtn--danger" type="submit" name="action" value="delete">
                        Yes, delete
                      </button>
                    </form>
                    <a class="button button--small button--ghost" href="history.php">Keep it</a>
                  </div>
                </div>
              <?php elseif (!$owing): ?>
                <p class="kticket__acts">
                  <a class="button button--small button--danger"
                     href="history.php?confirm=<?= urlencode($order['reference']) ?>">Delete</a>
                </p>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>

  </main>

<?php endif; ?>

<script src="js/script.js"></script>
</body>
</html>
