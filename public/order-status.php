<?php
/* ORDER TRACKING — "my orders" */

require __DIR__ . '/../src/setup.php';

$pageTitle = 'Your order';
$crumbs    = [['Home', 'index.php'], ['Track your order', null]];
$order     = null;
$error     = null;
$sentTo    = null;      // set once a code has gone out: drives the second step
$sentVia   = null;

$reference = strtoupper(trim((string) ($_POST['reference'] ?? $_GET['ref'] ?? '')));
$found     = null;

$me       = current_user();
$myOrders = [];          // the list, when signed in and no single order asked for

// How many past orders were attached when they signed in or registered.
$claimed  = (int) ($_SESSION['claimed_orders'] ?? 0);
unset($_SESSION['claimed_orders']);

try {
    // Signed in and no particular order asked for: show the list.
    if ($me !== null && $reference === '') {
        $myOrders = orders_for_user((int) $me['id']);
    }

    if ($reference !== '') {
        $found = getOrderByReference($reference);
    }

    if ($reference !== '' && !$found) {
        $error = 'We cannot find an order with that reference. Please check it and try again.';
    } elseif ($found && may_view_order($found)) {
        // may_view_order() asks the DATABASE whether this order belongs to the signed-in account.
        $order = $found;
    }

    $mine = $order !== null;   // kept for readability below

    /* ------------------------------------------------- asking for a code */
    if ($found && $order === null && ($_POST['action'] ?? null) === 'send-code') {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            $error = 'Your session expired. Please try again.';
        } else {
            $channel = ($_POST['channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
            $result  = issue_access_code($found, $channel);

            if ($result['ok']) {
                $sentVia = $channel;
                // Shown back partly hidden.
                $sentTo = $channel === 'sms'
                    ? mask_tail((string) $found['phone'], 3)
                    : mask_email((string) $found['email']);

                // Remembered so a page reload keeps showing the code box rather than dropping back to.
                $_SESSION['code_sent_for'] = $reference;
            } else {
                $error = $result['error'];
            }
        }
    }

    /* ---------------------------------------------------- checking a code */
    if ($found && $order === null && ($_POST['action'] ?? null) === 'check-code') {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            $error = 'Your session expired. Please try again.';
        } else {
            $result = check_access_code($found, (string) ($_POST['code'] ?? ''));

            if ($result['ok']) {
                $_SESSION['unlocked_orders'][] = $reference;
                unset($_SESSION['code_sent_for']);

                // Redirect so the code is not sitting in a form that a refresh would re-submit.
                header('Location: order-status.php?ref=' . urlencode($reference));
                exit;
            }

            $error   = $result['error'];
            $sentVia = 'sms';
            $sentTo  = null;   // keep the code box up, without re-announcing
        }
    }

    /* The customer cancelling their own order.
     *
     * Goes through the same move_order() the kitchen uses, so the board
     * sees it immediately, the change is written with its history row in
     * one transaction, and the usual cancellation email and SMS go out.
     * The actor is 'customer', so the kitchen's history says who did it. */
    if ($order && ($_POST['action'] ?? null) === 'cancel-order') {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            $error = 'Your session expired. Please try again.';
        } elseif (!in_array($order['status'], ['new', 'preparing', 'ready'], true)) {
            $error = 'That order can no longer be cancelled.';
        } else {
            $result = move_order($order['reference'], 'cancelled', 'customer');

            if ($result['ok']) {
                header('Location: order-status.php?ref=' . urlencode($order['reference']) . '&cancelled=1');
                exit;
            }

            $error = $result['error'];
        }
    }

    // A reload after a code was sent: stay on the code box.
    if ($found && $order === null && $sentVia === null
        && ($_SESSION['code_sent_for'] ?? null) === $reference) {
        $sentVia = 'sms';
    }

} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $error = 'We cannot reach the kitchen system right now. Please try again later.';
}

/* --------------------------------------------------------------- the words */

// Each kitchen stage, said twice.
$steps = [
    'new' => [
        'label' => 'Order received',
        'means' => 'We have it and the kitchen has been told.',
    ],
    'preparing' => [
        'label' => 'Being cooked',
        'means' => 'Your food is on the stove right now.',
    ],
    'ready' => [
        'label' => 'Ready',
        'means' => 'Cooked, packed and waiting for you.',
    ],
    'completed' => [
        'label' => 'With you',
        'means' => 'Handed over. Enjoy it.',
    ],
];

/* Hides the middle of a phone number: 0712345678 -> *******678 */
function mask_tail(string $value, int $keep = 3): string
{
    $digits = preg_replace('/\D+/', '', $value);

    if (strlen($digits) <= $keep) {
        return $digits;
    }

    return str_repeat('*', strlen($digits) - $keep) . substr($digits, -$keep);
}

/* k****n@gmail.com — enough to recognise, not enough to harvest. */
function mask_email(string $value): string
{
    [$name, $domain] = array_pad(explode('@', $value, 2), 2, '');

    if ($name === '' || $domain === '') {
        return '***';
    }

    $shown = mb_substr($name, 0, 1);
    $last  = mb_strlen($name) > 1 ? mb_substr($name, -1) : '';

    return $shown . str_repeat('*', max(1, mb_strlen($name) - 2)) . $last . '@' . $domain;
}

// KEEPING THE TWO PAGES IN STEP
$liveOrder = $order !== null
          && !in_array($order['status'], ['completed', 'cancelled'], true);

if ($liveOrder) {
    $metaRefresh = 30;
}

require __DIR__ . '/../src/header.php';
?>

<section class="section status-page">
  <div class="wrap">

    <?php if ($order): ?>

      <?php
        $cancelled = $order['status'] === 'cancelled';
        $place     = $order['order_type'] === 'delivery' ? 'Delivery' : 'Collection';
        $when      = order_when_words($order, $site);
        $stageNow  = array_search($order['status'], array_keys($steps), true);
        $stageNow  = $stageNow === false ? 0 : $stageNow;
        $current   = $steps[$order['status']] ?? $steps['new'];
        $paid      = $order['payment_status'] === 'paid';
        $payment   = latest_payment((int) $order['id']);

        // The one sentence that answers "so what now?".
        if ($cancelled) {
            $headline = 'This order was cancelled';
            $nextStep = 'Nothing is coming, and you have not been charged. Call us if that is wrong.';
        } elseif ($order['status'] === 'completed') {
            $headline = 'All done — thank you';
            $nextStep = 'Your order is finished. We hope it was good.';
        } elseif ($order['status'] === 'ready') {
            $headline = $order['order_type'] === 'delivery'
                ? 'Your food is on its way'
                : 'Your food is ready to collect';
            $nextStep = $order['order_type'] === 'delivery'
                ? 'The rider has it. Keep your phone nearby.'
                : 'Come to the counter and quote ' . $order['reference'] . '.';
        } elseif ($order['status'] === 'preparing') {
            $headline = 'Your food is being cooked';
            $nextStep = $order['order_type'] === 'delivery'
                ? 'We will set off once it is packed, for ' . $when . '.'
                : 'It will be ready for ' . $when . '.';
        } else {
            $headline = 'We have your order';
            $nextStep = 'The kitchen starts in good time for ' . $when . '.';
        }
      ?>

      <?php if (($_GET['cancel'] ?? '') === $order['reference']
                && in_array($order['status'], ['new', 'preparing', 'ready'], true)): ?>

        <!-- At the top, where the eye already is after clicking Cancel —
             at the foot it was below the fold on a long order. -->
        <div class="cancelbox">
          <p class="cancelbox__ask">
            Cancel <strong><?= e($order['reference']) ?></strong>?
            <?php if ($order['status'] !== 'new'): ?>
              The kitchen has already started this one — please ring
              <?= e($site['phone']) ?> as well so they can stop.
            <?php endif; ?>
            <?php if ($order['payment_status'] === 'paid'): ?>
              You have paid, so we will arrange your refund.
            <?php endif; ?>
          </p>
          <div class="cancelbox__acts">
            <form method="post" action="order-status.php">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="reference" value="<?= e($order['reference']) ?>">
              <button class="button button--danger" type="submit" name="action" value="cancel-order">
                Yes, cancel it
              </button>
            </form>
            <a class="button button--ghost"
               href="order-status.php?ref=<?= urlencode($order['reference']) ?>">Keep it</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- ========== the answer, before any detail ========== -->
      <header class="statushead<?= $cancelled ? ' statushead--off' : '' ?>">
        <p class="statushead__ref">
          Order <strong><?= e($order['reference']) ?></strong>
        </p>
        <h1 class="statushead__title"><?= e($headline) ?></h1>
        <p class="statushead__next"><?= e($nextStep) ?></p>

        <ul class="statushead__chips">
          <li class="schip">
            <span class="schip__k"><?= e($place) ?></span>
            <span class="schip__v"><?= e($when) ?></span>
          </li>
          <li class="schip">
            <span class="schip__k">Total</span>
            <span class="schip__v">KSh <?= number_format((float) $order['total'], 0) ?></span>
          </li>
          <li class="schip <?= $paid ? 'schip--good' : 'schip--owing' ?>">
            <span class="schip__k">Payment</span>
            <span class="schip__v"><?= $paid ? 'Paid' : 'Due on ' . strtolower($place) ?></span>
          </li>
        </ul>
      </header>

      <?php if (isset($_GET['cancelled'])): ?>
        <p class="msg msg--ok">
          <span class="msg__icon" aria-hidden="true">&check;</span>
          <span>Your order is cancelled and the kitchen has been told. You
                have not been charged.</span>
        </p>
      <?php endif; ?>

      <?php if (isset($_GET['paid'])): ?>
        <p class="msg msg--ok">
          <span class="msg__icon" aria-hidden="true">&check;</span>
          <span>
            <?= $_GET['paid'] === 'cash'
                ? 'Noted — you will pay when you get your food. The kitchen has your order.'
                : 'Payment received. Thank you!' ?>
          </span>
        </p>
      <?php endif; ?>

      <div class="status-grid">

        <!-- ========== left: where it is ========== -->
        <div class="card status-card">
          <h2 class="status-card__title">Where it is</h2>

          <?php if ($cancelled): ?>
            <p class="msg msg--bad">
              <span class="msg__icon" aria-hidden="true">!</span>
              <span>This order was cancelled. Call us on <?= e($site['phone']) ?> if that is wrong.</span>
            </p>
          <?php else: ?>
            <!-- Each step carries its own explanation, and the one you are on
                 is marked for a screen reader too, not just in colour. -->
            <ol class="track">
              <?php $i = 0; foreach ($steps as $key => $step): ?>
                <?php
                  $state = $i < $stageNow ? 'done' : ($i === $stageNow ? 'now' : 'todo');
                  $i++;
                ?>
                <li class="track__step track__step--<?= $state ?>">
                  <span class="track__dot" aria-hidden="true"></span>
                  <div class="track__body">
                    <p class="track__label">
                      <?= e($step['label']) ?>
                      <?php if ($state === 'now'): ?>
                        <span class="track__badge">Now</span>
                      <?php endif; ?>
                    </p>
                    <p class="track__means"><?= e($step['means']) ?></p>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>

            <p class="track__live">
              <?php if ($liveOrder): ?>
                Updating automatically &middot; last checked
                <?= e((new DateTimeImmutable())->format('g:i:s a')) ?>
              <?php else: ?>
                This order is finished, so it will not change again.
              <?php endif; ?>
            </p>
          <?php endif; ?>

          <dl class="status-facts">
            <?php if ($order['order_type'] === 'delivery'): ?>
              <dt>Going to</dt>
              <dd><?= nl2br(e($order['address'] ?? '')) ?></dd>
            <?php else: ?>
              <dt>Pick up from</dt>
              <dd><?= e($site['address']) ?></dd>
            <?php endif; ?>
            <dt>We will call</dt>
            <dd><?= e($order['phone']) ?></dd>
            <?php if (!empty($order['notes'])): ?>
              <dt>Your notes</dt>
              <dd><?= nl2br(e($order['notes'])) ?></dd>
            <?php endif; ?>
          </dl>
        </div>

        <!-- ========== right: the receipt ========== -->
        <div class="card status-card">
          <h2 class="status-card__title">What you ordered</h2>

          <ul class="receipt">
            <?php foreach ($order['items'] as $line): ?>
              <li class="receipt__row">
                <span class="receipt__qty"><?= (int) $line['quantity'] ?>&times;</span>
                <span class="receipt__name"><?= e($line['name']) ?></span>
                <span class="receipt__money">
                  KSh <?= number_format($line['quantity'] * $line['unit_price'], 0) ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>

          <dl class="order-sum">
            <dt>Food</dt>
            <dd>KSh <?= number_format((float) $order['subtotal'], 0) ?></dd>
            <?php if ((float) $order['delivery_fee'] > 0): ?>
              <dt>Delivery</dt>
              <dd>KSh <?= number_format((float) $order['delivery_fee'], 0) ?></dd>
            <?php endif; ?>
            <dt class="order-sum__total">Total</dt>
            <dd class="order-sum__total">KSh <?= number_format((float) $order['total'], 0) ?></dd>
          </dl>

          <!-- Payment, said in full. "Paid" on its own leaves people
               wondering what they paid with. -->
          <div class="paystate <?= $paid ? 'paystate--good' : 'paystate--owing' ?>">
            <?php if ($paid): ?>
              <p class="paystate__line">
                <span class="paystate__tick" aria-hidden="true">&check;</span>
                Paid in full by
                <?= $order['payment_method'] === 'mpesa' ? 'M-Pesa' : 'cash' ?>.
              </p>
              <?php if (!empty($payment['provider_ref'])): ?>
                <p class="paystate__sub">Receipt <?= e($payment['provider_ref']) ?></p>
              <?php endif; ?>
            <?php elseif ($order['payment_status'] === 'pending'): ?>
              <p class="paystate__line">We are still waiting for your M-Pesa payment.</p>
              <p class="paystate__sub">
                It usually lands in under a minute. Your food is booked in either way.
              </p>
            <?php else: ?>
              <p class="paystate__line">
                KSh <?= number_format((float) $order['total'], 0) ?> to pay when you
                <?= $order['order_type'] === 'delivery' ? 'take delivery' : 'collect' ?>.
              </p>
              <?php if (!$cancelled && in_array($reference, $_SESSION['my_orders'] ?? [], true)): ?>
                <p class="paystate__sub">
                  <a href="pay.php?ref=<?= urlencode($order['reference']) ?>">Pay now instead</a>
                </p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>


      <?php if (in_array($order['status'], ['new', 'preparing', 'ready'], true)
                && ($_GET['cancel'] ?? '') !== $order['reference']): ?>
        <p class="cancelline">
          Changed your mind?
          <a href="order-status.php?ref=<?= urlencode($order['reference']) ?>&amp;cancel=<?= urlencode($order['reference']) ?>">
            Cancel this order
          </a>
        </p>
      <?php endif; ?>

      <p class="status-again">
        <a class="button button--small" href="order.php">Order something else</a>
        <a class="button button--small button--ghost" href="menu.php">Back to the menu</a>
      </p>

    <?php elseif ($me): ?>

      <!-- ========== signed in: every order on this account ========== -->
      <div class="section-head">
        <span class="section-eyebrow">Your orders</span>
        <h1 class="section-title">
          <?= $myOrders ? 'Everything you have ordered' : 'No orders yet' ?>
        </h1>
      </div>

      <?php if (isset($_GET['welcome'])): ?>
        <p class="msg msg--ok">
          <span class="msg__icon" aria-hidden="true">&check;</span>
          <span>
            Your account is ready<?= $claimed > 0 ? ', and we found '
              . $claimed . ' earlier ' . ($claimed === 1 ? 'order' : 'orders')
              . ' on this email address' : '' ?>.
          </span>
        </p>
      <?php elseif ($claimed > 0): ?>
        <p class="msg msg--ok">
          <span class="msg__icon" aria-hidden="true">&check;</span>
          <span>We attached <?= $claimed ?> earlier
                <?= $claimed === 1 ? 'order' : 'orders' ?> to your account.</span>
        </p>
      <?php endif; ?>

      <?php if ($error): ?>
        <p class="msg msg--bad">
          <span class="msg__icon" aria-hidden="true">!</span>
          <span><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <?php if (!$myOrders): ?>

        <p class="kempty">
          Nothing here yet. When you order, it will show up on this page and
          you can follow it from the kitchen to your hands.
        </p>
        <p class="status-again">
          <a class="button" href="menu.php">See the menu</a>
          <a class="button button--ghost" href="order.php">Order food</a>
        </p>

      <?php else: ?>

        <p class="mylist__hint">
          <?= count($myOrders) === 1
              ? 'Tap the order to see what was in it.'
              : 'Your newest order is open. Tap any other to see what was in it.' ?>
        </p>

        <ul class="mylist">
          <?php foreach ($myOrders as $i => $row): ?>
            <?php
              $rowDue  = DateTimeImmutable::createFromFormat(
                            'Y-m-d H:i:s', $row['order_date'] . ' ' . $row['order_time']);
              $rowStep = $steps[$row['status']] ?? null;
              $live    = !in_array($row['status'], ['completed', 'cancelled'], true);
              $rowPaid = $row['payment_status'] === 'paid';
              $count   = array_sum(array_column($row['items'], 'quantity'));
            ?>
            <li>
              <!-- A plain <details>: collapsed saves the room, open shows the
                   food. The newest one starts open because it is the one being
                   waited on; the rest stay shut until asked for. Native, so it
                   needs no JavaScript and every order is still in the page for
                   a browser search to find. -->
              <details class="myorder<?= $live ? ' myorder--live' : '' ?>"<?= $i === 0 ? ' open' : '' ?>>
                <summary class="myorder__head">
                  <span class="myorder__main">
                    <span class="myorder__ref"><?= e($row['reference']) ?></span>
                    <span class="myorder__when">
                      <?= e($rowDue ? $rowDue->format('j M, g:i a') : '—') ?>
                      &middot; <?= (int) $count ?> <?= $count === 1 ? 'item' : 'items' ?>
                    </span>
                  </span>

                  <!-- A row of thumbnails, visible while collapsed, so you can
                       recognise an order without opening it. -->
                  <span class="myorder__peek" aria-hidden="true">
                    <?php foreach (array_slice($row['items'], 0, 4) as $line): ?>
                      <?php
                        $photo = trim((string) ($line['image'] ?? ''));
                        $has   = $photo !== '' && is_file(__DIR__ . '/images/' . basename($photo));
                      ?>
                      <?php if ($has): ?>
                        <img class="myorder__thumb" src="images/<?= e(basename($photo)) ?>"
                             alt="" loading="lazy" width="40" height="40">
                      <?php else: ?>
                        <span class="myorder__thumb myorder__thumb--none">
                          <?= e(mb_strtoupper(mb_substr($line['name'], 0, 1))) ?>
                        </span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count($row['items']) > 4): ?>
                      <span class="myorder__thumb myorder__thumb--more">
                        +<?= count($row['items']) - 4 ?>
                      </span>
                    <?php endif; ?>
                  </span>

                  <span class="myorder__tags">
                    <span class="mytag mytag--<?= e($row['status']) ?>">
                      <?= e($rowStep['label'] ?? ucfirst($row['status'])) ?>
                    </span>
                    <span class="mytag <?= $rowPaid ? 'mytag--paid' : 'mytag--owing' ?>">
                      <?= $rowPaid ? 'Paid' : 'Unpaid' ?>
                    </span>
                    <span class="myorder__total">KSh <?= number_format((float) $row['total'], 0) ?></span>
                  </span>
                </summary>

                <div class="myorder__body">
                  <ul class="mydishes">
                    <?php foreach ($row['items'] as $line): ?>
                      <?php
                        $photo = trim((string) ($line['image'] ?? ''));
                        $has   = $photo !== '' && is_file(__DIR__ . '/images/' . basename($photo));
                      ?>
                      <li class="mydish">
                        <?php if ($has): ?>
                          <img class="mydish__photo" src="images/<?= e(basename($photo)) ?>"
                               alt="<?= e($line['image_alt'] ?: $line['name']) ?>"
                               loading="lazy" width="64" height="64">
                        <?php else: ?>
                          <span class="mydish__photo mydish__photo--none" aria-hidden="true">
                            <?= e(mb_strtoupper(mb_substr($line['name'], 0, 1))) ?>
                          </span>
                        <?php endif; ?>
                        <span class="mydish__text">
                          <span class="mydish__name"><?= e($line['name']) ?></span>
                          <span class="mydish__qty"><?= (int) $line['quantity'] ?> &times; KSh
                            <?= number_format((float) $line['unit_price'], 0) ?></span>
                        </span>
                        <span class="mydish__money">
                          KSh <?= number_format($line['quantity'] * $line['unit_price'], 0) ?>
                        </span>
                      </li>
                    <?php endforeach; ?>
                  </ul>

                  <div class="myorder__foot">
                    <span class="myorder__where">
                      <?= $row['order_type'] === 'delivery' ? 'Delivery' : 'Collection' ?>
                      <?php if (!empty($row['address'])): ?>
                        &middot; <?= e($row['address']) ?>
                      <?php endif; ?>
                    </span>
                    <a class="button button--small"
                       href="order-status.php?ref=<?= urlencode($row['reference']) ?>">
                      <?= $live ? 'Track this order' : 'See the full order' ?>
                    </a>
                  </div>
                </div>
              </details>
            </li>
          <?php endforeach; ?>
        </ul>

        <p class="status-again">
          <a class="button button--small" href="order.php">Order again</a>
          <a class="button button--small button--ghost" href="account.php">Your account</a>
        </p>

      <?php endif; ?>

    <?php else: ?>

      <!-- ========== signed out ========== -->
      <div class="section-head">
        <span class="section-eyebrow">Already ordered?</span>
        <h1 class="section-title">Sign in to see your orders</h1>
        <p class="section-lead">
          Your orders live on your account, so there is no reference to find
          and no code to wait for.
        </p>
      </div>

      <?php if ($error): ?>
        <p class="msg msg--bad">
          <span class="msg__icon" aria-hidden="true">!</span>
          <span><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <p class="status-again status-again--lead">
        <a class="button button--lg" href="account.php?next=order-status.php">Sign in</a>
        <a class="button button--lg button--ghost"
           href="account.php?mode=register&amp;next=order-status.php">Create an account</a>
      </p>

      <!-- The old reference-and-code route, kept for orders placed before
           accounts existed: those have no user_id, so there is no account to
           match them against. Registering with the same email claims them
           automatically, which is the usual way back in; this is the
           fallback when the address differs. -->
      <details class="oldway">
        <summary>Ordered before you had an account?</summary>

        <div class="card status-lookup">

        <?php if ($found && $sentVia !== null): ?>

          <!-- Step two: the code. -->
          <h2 class="status-lookup__title">Type the code we sent</h2>
          <p class="reserve__card-note">
            <?php if ($sentTo !== null): ?>
              We sent a six-digit code to <strong><?= e($sentTo) ?></strong>.
              It works for ten minutes.
            <?php else: ?>
              Type the six-digit code we sent you.
            <?php endif; ?>
          </p>

          <form method="post" action="order-status.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="reference" value="<?= e($reference) ?>">

            <div class="field">
              <label class="field__label" for="code">Six-digit code</label>
              <input class="field__input field__input--code" type="text" id="code" name="code"
                     inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                     pattern="[0-9]*" placeholder="000000" required autofocus
                     spellcheck="false">
              <span class="field__hint">We will never ask you for this on the phone.</span>
            </div>

            <button class="button button--block" type="submit" name="action" value="check-code">
              See my order
            </button>
          </form>

          <!-- Two forms, not two buttons in one. A button carries a single
               name/value pair, and that pair is already spoken for by
               "action" — so the channel has to travel as its own hidden
               field, in its own form. -->
          <div class="status-lookup__again">
            <form method="post" action="order-status.php">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="reference" value="<?= e($reference) ?>">
              <input type="hidden" name="channel" value="sms">
              <button class="linkbtn" type="submit" name="action" value="send-code">
                Text it again
              </button>
            </form>

            <span class="status-lookup__sep" aria-hidden="true">&middot;</span>

            <form method="post" action="order-status.php">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="reference" value="<?= e($reference) ?>">
              <input type="hidden" name="channel" value="email">
              <button class="linkbtn" type="submit" name="action" value="send-code">
                Email it instead
              </button>
            </form>
          </div>

        <?php else: ?>

          <!-- Step one: the reference. -->
          <p class="reserve__card-note">
            Type the reference from your confirmation — it looks like
            <code>MD-7K3QB2</code> and is in the email we sent you. We will
            text a code to the number on the order to make sure it is you.
          </p>

          <form method="post" action="order-status.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="field">
              <label class="field__label" for="reference">Order reference</label>
              <input class="field__input" type="text" id="reference" name="reference"
                     value="<?= e($reference) ?>" placeholder="MD-7K3QB2" required
                     autocapitalize="characters" spellcheck="false" autocomplete="off">
            </div>

            <button class="button button--block" type="submit" name="action" value="send-code">
              Text me a code
            </button>
          </form>

        <?php endif; ?>

        </div><!-- /.status-lookup -->
      </details>

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/../src/footer.php'; ?>
