<?php
/* ORDER PAGE */

require __DIR__ . '/includes/setup.php';

// Ordering needs an account.
if (!logged_in()) {
    header('Location: account.php?mode=register&next=order.php&why=order');
    exit;
}

$me = current_user();

$pageTitle = 'Order food';
$crumbs    = [['Home', 'index.php'], ['Menu', 'menu.php'], ['Order food', null]];
$errors    = [];

// The three contact fields come from the account.
$old = ['name' => $me['name'], 'email' => $me['email'], 'phone' => $me['phone'],
        'order_type' => 'collection',
        'address' => '', 'order_date' => date('Y-m-d'),
        'order_time' => array_key_first($site['order_times']), 'notes' => ''];

$quantities = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $_) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            $old[$key] = trim((string) $_POST[$key]);
        } elseif ($key !== 'order_date' && $key !== 'order_time' && $key !== 'order_type') {
            $old[$key] = '';
        }
    }
    $quantities = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];

} elseif (isset($_GET['item'])) {
    // Came from an "Add to order" button on the home page.
    $quantities[(int) $_GET['item']] = 1;
}

// Fall back to a time we recognise.
if (!isset($site['order_times'][$old['order_time']])) {
    $old['order_time'] = array_key_first($site['order_times']);
}
if (!in_array($old['order_type'], ['collection', 'delivery'], true)) {
    $old['order_type'] = 'collection';
}

$rules = $site['ordering'];
$menu  = [];
$order = ['lines' => [], 'subtotal' => 0.0, 'dropped' => []];

try {
    $menu  = getOrderableMenu();
    $order = buildOrderLines($quantities, $rules['max_per_dish']);
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $errors['form'] = 'We cannot reach the kitchen system right now. Please try again later.';
}

// Delivery costs a flat fee; collection costs nothing.
$deliveryFee = ($old['order_type'] === 'delivery' && $order['lines']) ? (float) $rules['delivery_fee'] : 0.0;
$orderTotal  = $order['subtotal'] + $deliveryFee;

// The earliest the kitchen will take an order for.
$readyFrom = (new DateTimeImmutable())->modify('+' . (int) $rules['lead_minutes'] . ' minutes');

// Open on a slot the kitchen can actually cook.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($site['order_times'] as $slot => $label) {
        $slotAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s', $old['order_date'] . ' ' . $slot
        );

        if ($slotAt && $slotAt >= $readyFrom) {
            $old['order_time'] = $slot;
            break;
        }
    }
}

/* -------------------------------------------------------------- placing it */

if (($_POST['action'] ?? null) === 'order' && !isset($errors['form'])) {

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    }

    if ($order['dropped']) {
        // Something sold out between drawing the page and pressing the button.
        $errors['items'] = 'Sorry — ' . implode(', ', $order['dropped'])
                         . ' just became unavailable. Your other dishes are still here;'
                         . ' please check the order and send it again.';
    } elseif (!$order['lines']) {
        $errors['items'] = 'Choose at least one dish.';
    }

    if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 100) {
        $errors['name'] = 'Enter your name (2 to 100 characters).';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 150) {
        $errors['email'] = 'Enter a valid email address.';
    }

    // Unlike a booking, a phone number is required: the kitchen rings you.
    if (!preg_match('/^\+?[0-9 ]{7,20}$/', $old['phone'])) {
        $errors['phone'] = 'Enter a phone number we can reach you on.';
    }

    if ($old['order_type'] === 'delivery') {
        if (mb_strlen($old['address']) < 6) {
            $errors['address'] = 'Enter the address we are delivering to.';
        }
        if ($order['lines'] && $order['subtotal'] < $rules['delivery_minimum']) {
            $errors['items'] = sprintf(
                'Delivery starts at KSh %s of food. Your order is KSh %s — add a little more, or switch to collection.',
                number_format($rules['delivery_minimum'], 0),
                number_format($order['subtotal'], 0)
            );
        }
    }

    // The slot must be one we actually offer.
    if (!isset($site['order_times'][$_POST['order_time'] ?? ''])) {
        $errors['order_time'] = 'Choose one of the collection times.';
    }

    $date = DateTime::createFromFormat('Y-m-d', $old['order_date']);
    if (!$date || $date->format('Y-m-d') !== $old['order_date']) {
        $errors['order_date'] = 'Choose a valid date.';
    } elseif (empty($errors['order_time'])) {
        // Date and time together.
        $when = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s', $old['order_date'] . ' ' . $old['order_time']
        );

        if (!$when) {
            $errors['order_date'] = 'Choose a valid date and time.';
        } elseif ($when < new DateTimeImmutable()) {
            $errors['order_time'] = 'That time has already passed. Pick a later one.';
        } elseif ($when < $readyFrom) {
            $errors['order_time'] = sprintf(
                'We need %d minutes to cook. The earliest today is %s.',
                $rules['lead_minutes'], $readyFrom->format('g:i a')
            );
        }
    }

    if (mb_strlen($old['notes']) > 500) {
        $errors['notes'] = 'Keep your notes under 500 characters.';
    }

    if (!$errors) {
        $pdo = getPDO();

        try {
            // Either the order AND all its dishes are saved, or nothing is.
            $pdo->beginTransaction();

            $reference = makeOrderReference();

            $stmt = $pdo->prepare(
                'INSERT INTO orders
                   (reference, user_id, name, email, phone, order_type, address,
                    order_date, order_time, notes, subtotal, delivery_fee, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $reference,
                $me['id'],
                $old['name'],
                $old['email'],
                $old['phone'],
                $old['order_type'],
                $old['order_type'] === 'delivery' ? $old['address'] : null,
                $old['order_date'],
                $old['order_time'],
                $old['notes'] !== '' ? $old['notes'] : null,
                $order['subtotal'],
                $deliveryFee,
                $orderTotal,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $line = $pdo->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($order['lines'] as $l) {
                $line->execute([$orderId, $l['menu_item_id'], $l['quantity'], $l['unit_price']]);
            }

            // The first entry in the order's history.
            $pdo->prepare(
                "INSERT INTO order_events (order_id, from_status, to_status, actor)
                 VALUES (?, NULL, 'new', 'customer')"
            )->execute([$orderId]);

            $pdo->commit();

            // Remember which orders this visitor placed.
            $_SESSION['my_orders'][] = $reference;

            // Tell them straight away, BEFORE the payment screen.
            $placed = getOrderByReference($reference);
            if ($placed) {
                notify_order_placed($placed);
            }

            // On to paying.
            header('Location: pay.php?ref=' . urlencode($reference));
            exit;

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors['form'] = 'Sorry, we could not place your order. Please try again.';
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$bad = static fn(string $field): string => !empty($errors[$field]) ? ' field--bad' : '';

require __DIR__ . '/includes/header.php';
?>

<section class="order-page">
  <div class="wrap">

    <div class="section-head">
      <h1 class="section-title">Order food</h1>
    </div>

    <?php if ($flash): ?>
      <p class="msg msg--ok">
        <span class="msg__icon" aria-hidden="true">&check;</span>
        <span><?= e($flash) ?></span>
      </p>
    <?php endif; ?>

    <?php if (!empty($errors['form'])): ?>
      <p class="msg msg--bad">
        <span class="msg__icon" aria-hidden="true">!</span>
        <span><?= e($errors['form']) ?></span>
      </p>
    <?php endif; ?>

    <form method="post" action="order.php" class="order-form" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="checkout">

        <!-- ========== left: find a dish, then pick it ========== -->
        <div class="checkout__main">

          <label class="search" for="dish-search">
            <span class="search__icon" aria-hidden="true">&#9906;</span>
            <input class="search__input" type="search" id="dish-search"
                   placeholder="Search the menu" autocomplete="off" data-dish-search>
          </label>

          <?php require __DIR__ . '/includes/sections/order/dishes.php'; ?>


          <?php require __DIR__ . '/includes/sections/order/details.php'; ?>


        </div>

        <!-- ========== right: the receipt ========== -->
        <?php require __DIR__ . '/includes/sections/order/basket.php'; ?>


      </div>

    </form>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
