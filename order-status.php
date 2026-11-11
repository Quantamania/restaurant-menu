<?php
/* ORDER TRACKING — "my orders" */

require __DIR__ . '/includes/setup.php';

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

    /* The customer deleting their own cancelled order.
     *
     * Same delete_order() the kitchen uses, so the rules are identical in
     * both places: only a cancelled order goes, a paid one never does —
     * the row is the only record of the money — and it is logged first.
     * Guarded on $order, not $found, so it inherits the ownership check
     * may_view_order() already made. */
    if (($_POST['action'] ?? null) === 'delete-order') {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            $error = 'Your session expired. Please try again.';
        } else {
            /* The single-order view has $order already. The my-orders list has
             * no ?ref=, so the order is looked up from the posted reference and
             * checked with may_view_order() — the same gate either way. */
            $target = $order;

            if ($target === null) {
                $posted = strtoupper(trim((string) ($_POST['reference'] ?? '')));
                $maybe  = $posted !== '' ? getOrderByReference($posted) : null;

                if ($maybe && may_view_order($maybe)) {
                    $target = $maybe;
                }
            }

            if ($target === null) {
                $error = 'We cannot find that order.';
            } else {
                $gone   = $target['reference'];
                $result = delete_order($gone, 'customer');

                if ($result['ok']) {
                    // Drop it from the guest list too, or it lingers as a dead link.
                    if (!empty($_SESSION['my_orders'])) {
                        $_SESSION['my_orders'] = array_values(
                            array_diff($_SESSION['my_orders'], [$gone])
                        );
                    }

                    /* ?ref= would only 404 now, so go back to the list. */
                    header('Location: order-status.php?deleted=' . urlencode($gone));
                    exit;
                }

                $error = $result['error'];
            }
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

require __DIR__ . '/includes/header.php';
?>

<section class="section status-page">
  <div class="wrap">

    <?php if ($order): ?>
      <?php require __DIR__ . '/includes/sections/order-status/one-order.php'; ?>


    <?php elseif ($me): ?>
      <?php require __DIR__ . '/includes/sections/order-status/my-orders.php'; ?>


    <?php else: ?>
      <?php require __DIR__ . '/includes/sections/order-status/find-order.php'; ?>


    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
