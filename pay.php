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
    <?php require __DIR__ . '/includes/sections/pay/choose-method.php'; ?>
    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
