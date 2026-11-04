<?php
/* PAYMENT PAGE */

require __DIR__ . '/includes/setup.php';

$pageTitle = 'Pay for your order';
$crumbs    = [['Home', 'index.php'], ['Order food', 'order.php'], ['Payment', null]];
$errors    = [];
$notice    = null;

$reference = strtoupper(trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? '')));
$order     = null;

try {
    if ($reference !== '') {
        $order = getOrderByReference($reference);
    }
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $errors['form'] = 'We cannot reach the payment system right now. Please try again.';
}

// Not ours to show.
if ($order && !in_array($reference, $_SESSION['my_orders'] ?? [], true)) {
    header('Location: order-status.php?ref=' . urlencode($reference));
    exit;
}

if (!$order && !isset($errors['form'])) {
    $errors['form'] = 'We cannot find that order.';
}

// Already settled — there is nothing to do here, so do not offer to charge again.
if ($order && $order['payment_status'] === 'paid') {
    header('Location: order-status.php?ref=' . urlencode($reference));
    exit;
}

$payment = $order ? latest_payment((int) $order['id']) : null;

/* ------------------------------------------------------- choosing a method */

if ($order && ($_POST['action'] ?? null) === 'pay' && !isset($errors['form'])) {

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $method = (string) ($_POST['method'] ?? '');
        $phone  = (string) ($_POST['mpesa_phone'] ?? '');

        if ($method === 'mpesa' && normalise_phone($phone !== '' ? $phone : $order['phone']) === null) {
            $errors['mpesa_phone'] = 'Enter the M-Pesa number to charge, like 0712 345 678.';
        } else {
            $result = start_payment($order, $method, $phone);

            if (!$result['ok']) {
                $errors['form'] = $result['error'];
            } elseif ($method === 'cash') {
                // Nothing to wait for: the kitchen can start.
                header('Location: order-status.php?ref=' . urlencode($reference) . '&paid=cash');
                exit;
            } else {
                // Redirect rather than fall through to the HTML.
                header('Location: pay.php?ref=' . urlencode($reference) . '&sent=1');
                exit;
            }
        }
    }
}

/* ------------------------------------------------- a development shortcut -- */

function is_local_request(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.test');
}

$devMode = payments_driver() === 'manual' && is_local_request();

if ($order && $devMode && ($_POST['action'] ?? null) === 'dev-settle') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($payment) {
        settle_payment((int) $payment['id'], 'DEV-' . strtoupper(bin2hex(random_bytes(3))),
                       'Marked paid by the local development control');
        header('Location: order-status.php?ref=' . urlencode($reference) . '&paid=mpesa');
        exit;
    }
}

if (isset($_GET['sent'])) {
    $notice = 'sent';
}

// Waiting for M-Pesa to land.
$waiting = $order
        && $order['payment_status'] === 'pending'
        && $payment
        && $payment['method'] === 'mpesa'
        && !isset($_GET['retry']);

$pay = $site['payment'] ?? [];

require __DIR__ . '/includes/header.php';
?>

<section class="section pay-page">
  <div class="wrap wrap--narrow">

    <?php if (!$order): ?>

      <div class="section-head">
        <h1 class="section-title">Payment</h1>
      </div>
      <p class="msg msg--bad">
        <span class="msg__icon" aria-hidden="true">!</span>
        <span><?= e($errors['form'] ?? 'We cannot find that order.') ?></span>
      </p>
      <p class="status-again"><a class="button button--small" href="order.php">Start an order</a></p>

    <?php else: ?>

      <?php
        $when  = order_when_words($order, $site);
        $how   = $order['order_type'] === 'delivery' ? 'Delivery' : 'Collection';
        $total = number_format((float) $order['total'], 0);
      ?>

      <!-- Where you are in the ordering flow. Three steps, the third live. -->
      <ol class="payflow" aria-label="Progress">
        <li class="payflow__step payflow__step--done">
          <span class="payflow__tick" aria-hidden="true">&check;</span> Your food
        </li>
        <li class="payflow__step payflow__step--done">
          <span class="payflow__tick" aria-hidden="true">&check;</span> Your details
        </li>
        <li class="payflow__step payflow__step--now" aria-current="step">
          <span class="payflow__dot" aria-hidden="true"></span> Payment
        </li>
      </ol>

      <div class="section-head">
        <span class="section-eyebrow">Order <?= e($order['reference']) ?></span>
        <h1 class="section-title">
          <?= $waiting ? 'Finishing your payment' : 'How would you like to pay?' ?>
        </h1>
      </div>

      <!-- The order is already safe. Saying so here is the difference between
           a payment screen that feels like a checkout and one that feels like
           the last chance to not lose your food. -->
      <p class="msg msg--ok">
        <span class="msg__icon" aria-hidden="true">&check;</span>
        <span>
          Your order is saved and we have emailed <?= e($order['email']) ?> the
          reference. Paying now is optional — you can pay when you
          <?= $order['order_type'] === 'delivery' ? 'take delivery' : 'collect' ?>.
        </span>
      </p>

      <?php if (!empty($errors['form'])): ?>
        <p class="msg msg--bad">
          <span class="msg__icon" aria-hidden="true">!</span>
          <span><?= e($errors['form']) ?></span>
        </p>
      <?php endif; ?>

      <?php if ($order['payment_status'] === 'failed'): ?>
        <p class="msg msg--bad">
          <span class="msg__icon" aria-hidden="true">!</span>
          <span>
            That payment did not go through<?= !empty($payment['provider_note'])
              ? ' — ' . e($payment['provider_note']) : '' ?>.
            Nothing has been taken. Try again below, or pay on
            <?= $order['order_type'] === 'delivery' ? 'delivery' : 'collection' ?>.
          </span>
        </p>
      <?php endif; ?>

      <div class="paygrid">

        <!-- ========== left: what you are paying for ========== -->
        <aside class="card paysum">
          <h2 class="paysum__title">What you are paying for</h2>

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
            <dt class="order-sum__total">To pay</dt>
            <dd class="order-sum__total">KSh <?= $total ?></dd>
          </dl>

          <dl class="paysum__facts">
            <dt><?= e($how) ?></dt>
            <dd><?= e($when) ?></dd>
          </dl>
        </aside>

        <!-- ========== right: how ========== -->
        <div class="card paymethods form-brown">

          <?php if ($waiting): ?>

            <!-- Waiting for the money to land. -->
            <div class="paywait">
              <span class="paywait__spin" aria-hidden="true"></span>
              <h2 class="paywait__title">Waiting for your payment</h2>

              <?php if ($notice === 'sent' && payments_driver() === 'mpesa'): ?>
                <p class="paywait__text">
                  Check <strong><?= e($payment['phone'] ?? $order['phone']) ?></strong> — there
                  should be an M-Pesa prompt asking for your PIN. It can take
                  a few seconds to arrive.
                </p>
              <?php elseif (($pay['paybill'] ?? '') !== '' || ($pay['till'] ?? '') !== ''): ?>
                <p class="paywait__text">Send <strong>KSh <?= $total ?></strong> on M-Pesa:</p>
                <ol class="paysteps">
                  <?php if (($pay['paybill'] ?? '') !== ''): ?>
                    <li>Lipa na M-Pesa &rarr; Pay Bill</li>
                    <li>Business number <strong><?= e($pay['paybill']) ?></strong></li>
                    <li>Account number <strong><?= e($order['reference']) ?></strong></li>
                  <?php else: ?>
                    <li>Lipa na M-Pesa &rarr; Buy Goods and Services</li>
                    <li>Till number <strong><?= e($pay['till']) ?></strong></li>
                  <?php endif; ?>
                  <li>Amount <strong><?= $total ?></strong></li>
                </ol>
                <p class="paywait__text">
                  We will mark your order paid as soon as it comes through.
                </p>
              <?php else: ?>
                <!-- No till configured and no gateway: say so plainly rather
                     than printing instructions that cannot be followed. -->
                <p class="paywait__text">
                  We have noted that you want to pay by M-Pesa. Our card is not
                  set up for payment online yet, so please pay when you
                  <?= $order['order_type'] === 'delivery' ? 'take delivery' : 'collect' ?>
                  — or call us on <?= e($site['phone']) ?> and we will take it
                  over the phone.
                </p>
              <?php endif; ?>

              <p class="paywait__note">
                Your food is already booked in. You can close this page.
              </p>

              <div class="paywait__acts">
                <a class="button button--small" href="order-status.php?ref=<?= urlencode($reference) ?>">
                  Go to my order
                </a>
                <a class="button button--small button--ghost" href="pay.php?ref=<?= urlencode($reference) ?>&amp;retry=1">
                  Choose another way
                </a>
              </div>

              <?php if ($devMode): ?>
                <!-- Local development only: stands in for the gateway's
                     callback so the rest of the flow can be tested. Does not
                     render on a real host. -->
                <form method="post" action="pay.php" class="devtool">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="ref" value="<?= e($reference) ?>">
                  <p class="devtool__label">Development only — no gateway is configured</p>
                  <button class="button button--small" type="submit" name="action" value="dev-settle">
                    Simulate a successful payment
                  </button>
                </form>
              <?php endif; ?>
            </div>

          <?php else: ?>

            <h2 class="paymethods__title">How would you like to pay?</h2>

            <!-- Two separate actions, not one radio group behind a single button.
                 Paying later is not a kind of paying: making it a choice you then
                 confirm with "Pay KSh 1,200" asked people to press Pay in order
                 not to pay. Each route is now its own form and its own button. -->

            <form method="post" action="pay.php" class="payroute payroute--now">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="ref" value="<?= e($reference) ?>">
              <input type="hidden" name="method" value="mpesa">

              <div class="payroute__head">
                <span class="payroute__name">M-Pesa</span>
                <span class="paymethod__tag">Pay now</span>
              </div>
              <p class="payroute__desc">
                <?= payments_driver() === 'mpesa'
                    ? 'We will send a prompt to your phone. Enter your PIN and you are done.'
                    : 'Send the money from your phone. We confirm it against the order.' ?>
              </p>

              <div class="field<?= !empty($errors['mpesa_phone']) ? ' field--bad' : '' ?>">
                <label class="field__label" for="mpesa_phone">M-Pesa number</label>
                <input class="field__input" type="tel" id="mpesa_phone" name="mpesa_phone"
                       value="<?= e($order['phone']) ?>" placeholder="0712 345 678"
                       inputmode="tel" autocomplete="tel">
                <?php if (!empty($errors['mpesa_phone'])): ?>
                  <span class="field__error"><?= e($errors['mpesa_phone']) ?></span>
                <?php else: ?>
                  <span class="field__hint">Change it if somebody else is paying.</span>
                <?php endif; ?>
              </div>

              <button class="button button--lg button--block" type="submit" name="action" value="pay">
                Pay KSh <?= $total ?>
              </button>
            </form>

            <div class="payroute__or"><span>or</span></div>

            <form method="post" action="pay.php" class="payroute">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="ref" value="<?= e($reference) ?>">
              <input type="hidden" name="method" value="cash">

              <div class="payroute__head">
                <span class="payroute__name">
                  Pay on <?= $order['order_type'] === 'delivery' ? 'delivery' : 'collection' ?>
                </span>
                <span class="paymethod__tag paymethod__tag--quiet">Nothing now</span>
              </div>
              <p class="payroute__desc">
                <?= $order['order_type'] === 'delivery'
                    ? 'Cash or M-Pesa to the rider when your food arrives.'
                    : 'Cash or M-Pesa at the counter when you pick it up.' ?>
              </p>

              <!-- Goes straight to the tracking page: there is nothing to pay
                   here, so there is nothing to confirm. -->
              <button class="button button--lg button--block button--outline"
                      type="submit" name="action" value="pay">
                I will pay later &rarr;
              </button>
            </form>

          <?php endif; ?>

        </div>
      </div>

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
