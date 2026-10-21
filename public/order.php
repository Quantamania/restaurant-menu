<?php
/* ===========================================================================
 * ORDER PAGE
 *
 * Ordering food and booking a table are two separate things. This page only
 * handles food: it saves into the "orders" and "order_items" tables and never
 * touches reservations.
 *
 * Part 1 (PHP)  - if the form was sent, check it and save the order
 * Part 2 (HTML) - the dishes with quantity boxes, then the delivery details
 *
 * Arriving from a dish on the home page (order.php?item=7) starts that dish
 * at a quantity of 1.
 * =========================================================================== */

require __DIR__ . '/../src/setup.php';

// Ordering needs an account. Checked BEFORE anything is read or written, so
// there is no half-built order sitting in a session belonging to nobody.
//
// ?why=order tells the account page to explain itself rather than just
// appearing in the way, and ?next brings them straight back here.
if (!logged_in()) {
    header('Location: account.php?mode=register&next=order.php&why=order');
    exit;
}

$me = current_user();

$pageTitle = 'Order food';
$crumbs    = [['Home', 'index.php'], ['Menu', 'menu.php'], ['Order food', null]];
$errors    = [];

// The three contact fields come from the account, so a returning customer
// does not retype them. They stay editable: "deliver to my sister's" is a
// real order, and the values are copied onto the order row anyway, so
// changing them here does not change the account.
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
//
// The default used to be the first slot of the day, which by lunchtime is
// hours gone — so the form loaded with "11:30 am — too soon" selected, and
// anyone who did not notice got their order refused on submit for a choice
// they never made. Only applied when the visitor has not chosen a time
// themselves; their own pick is never silently moved, it is refused with a
// message by the validation below.
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
        // Say so plainly rather than quietly charging for less food.
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

    // The slot must be one we actually offer. A value we do not recognise is
    // refused, not quietly swapped for another time the customer never chose.
    if (!isset($site['order_times'][$_POST['order_time'] ?? ''])) {
        $errors['order_time'] = 'Choose one of the collection times.';
    }

    $date = DateTime::createFromFormat('Y-m-d', $old['order_date']);
    if (!$date || $date->format('Y-m-d') !== $old['order_date']) {
        $errors['order_date'] = 'Choose a valid date.';
    } elseif (empty($errors['order_time'])) {
        // Date and time together: the kitchen needs notice, and nobody can
        // order for a slot that has already gone by.
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

            // The first entry in the order's history, written inside the same
            // transaction as the order itself. Without it the kitchen screen
            // shows a timeline that begins halfway through — the first thing
            // that ever happened to the order would be missing from it.
            $pdo->prepare(
                'INSERT INTO order_events (order_id, from_status, to_status, actor)
                 VALUES (?, NULL, "new", "customer")'
            )->execute([$orderId]);

            $pdo->commit();

            // Remember which orders this visitor placed, so the status page can
            // show them in full without asking for a code.
            $_SESSION['my_orders'][] = $reference;

            // Tell them straight away, BEFORE the payment screen. The
            // reference is what they need if anything goes wrong while
            // paying, so it has to reach them even if they close the tab at
            // the next step. Sending cannot fail the order: notify_* swallows
            // its own errors into the notifications table.
            $placed = getOrderByReference($reference);
            if ($placed) {
                notify_order_placed($placed);
            }

            // On to paying. The order is saved either way — payment is a
            // separate step that can be retried, abandoned and come back to,
            // which is why it is its own page with its own URL.
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

require __DIR__ . '/../src/header.php';
?>

<section class="order-page">
  <div class="wrap">

    <div class="section-head">
      <span class="section-eyebrow">Order ahead</span>
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

          <section class="block">
            <header class="block__head">
              <div>
                <h2 class="block__title">Categories</h2>
                <p class="block__sub"><?= count($menu) ?> courses on the menu today</p>
              </div>
              <a class="block__more" href="menu.php">
                See the menu
                <span aria-hidden="true">&rsaquo;</span>
              </a>
            </header>

            <div class="cats" role="group" aria-label="Filter by category">
              <button class="cat is-on" type="button" data-filter="*">
                <span class="cat__disc" aria-hidden="true">All</span>
                <span class="cat__name">Everything</span>
              </button>
              <?php foreach ($menu as $categoryName => $items): ?>
                <button class="cat" type="button" data-filter="<?= e($categoryName) ?>">
                  <span class="cat__disc" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($categoryName, 0, 1))) ?>
                  </span>
                  <span class="cat__name"><?= e($categoryName) ?></span>
                  <span class="cat__count"><?= count($items) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="block">
            <header class="block__head">
              <div>
                <h2 class="block__title">Dishes</h2>
                <p class="block__sub"><span data-result-count></span> to choose from</p>
              </div>
              <a class="block__more" href="#details">
                Your details
                <span aria-hidden="true">&rsaquo;</span>
              </a>
            </header>

            <?php if (!empty($errors['items'])): ?>
              <p class="msg msg--bad">
                <span class="msg__icon" aria-hidden="true">!</span>
                <span><?= e($errors['items']) ?></span>
              </p>
            <?php endif; ?>

            <!-- The same rows as the menu page, with a stepper on the end.
                 Not small cards: a dish reads better as a line you can scan. -->
            <ul class="dishes">
              <?php foreach ($menu as $categoryName => $items): ?>
                <?php foreach ($items as $item): ?>
                  <?php
                    $qty   = (int) ($quantities[$item['id']] ?? 0);
                    $photo = trim((string) ($item['image'] ?? ''));
                    $has   = $photo !== '' && is_file(__DIR__ . '/images/' . basename($photo));
                  ?>
                  <li class="dish<?= $qty > 0 ? ' is-chosen' : '' ?>"
                      id="item-<?= (int) $item['id'] ?>"
                      data-pick
                      data-name="<?= e($item['name']) ?>"
                      data-desc="<?= e(mb_strimwidth((string) $item['description'], 0, 34, '…')) ?>"
                      data-photo="<?= $has ? e('images/' . basename($photo)) : '' ?>"
                      data-price="<?= (float) $item['price'] ?>"
                      data-course="<?= e($categoryName) ?>">

                    <?php if ($has): ?>
                      <img class="dish__photo" src="images/<?= e(basename($photo)) ?>"
                           alt="" loading="lazy" width="112" height="112">
                    <?php else: ?>
                      <span class="dish__photo dish__photo--none" aria-hidden="true">
                        <?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?>
                      </span>
                    <?php endif; ?>

                    <div class="dish__text">
                      <h3 class="dish__name">
                        <label for="qty-<?= (int) $item['id'] ?>"><?= e($item['name']) ?></label>
                      </h3>
                      <?php if (trim((string) $item['description']) !== ''): ?>
                        <p class="dish__desc"><?= e($item['description']) ?></p>
                      <?php endif; ?>
                    </div>

                    <p class="dish__price">KSh <?= number_format((float) $item['price'], 0) ?></p>

                    <div class="qty" data-qty>
                      <input class="qty__input" type="number" min="0"
                             max="<?= (int) $rules['max_per_dish'] ?>" step="1"
                             id="qty-<?= (int) $item['id'] ?>"
                             name="qty[<?= (int) $item['id'] ?>]"
                             value="<?= $qty ?>"
                             aria-label="Quantity of <?= e($item['name']) ?>">
                    </div>

                    <span class="dish__badge" data-card-badge<?= $qty > 0 ? '' : ' hidden' ?>><?= $qty ?></span>
                  </li>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </ul>

            <p class="dish-grid__empty" data-no-results hidden>
              Nothing on the menu matches that.
            </p>
          </section>

          <section class="card details-card form-brown" id="details">
            <header class="block__head">
              <div>
                <h2 class="block__title">Your details</h2>
                <p class="block__sub">We only use these to confirm your order</p>
              </div>
            </header>

            <div class="form-grid">

              <div class="field<?= $bad('name') ?>">
                <label class="field__label" for="name">Name<span class="field__req" title="required" aria-label="required">*</span></label>
                <input class="field__input" type="text" id="name" name="name"
                       value="<?= e($old['name']) ?>" placeholder="Your full name" required maxlength="100">
                <?php if (!empty($errors['name'])): ?>
                  <span class="field__error"><?= e($errors['name']) ?></span>
                <?php endif; ?>
              </div>

              <div class="field<?= $bad('phone') ?>">
                <label class="field__label" for="phone">Phone<span class="field__req" title="required" aria-label="required">*</span></label>
                <input class="field__input" type="tel" id="phone" name="phone"
                       value="<?= e($old['phone']) ?>" placeholder="+254 700 000 000" required>
                <?php if (!empty($errors['phone'])): ?>
                  <span class="field__error"><?= e($errors['phone']) ?></span>
                <?php endif; ?>
              </div>

              <div class="field<?= $bad('email') ?>">
                <label class="field__label" for="email">Email<span class="field__req" title="required" aria-label="required">*</span></label>
                <input class="field__input" type="email" id="email" name="email"
                       value="<?= e($old['email']) ?>" placeholder="you@example.com" required maxlength="150">
                <?php if (!empty($errors['email'])): ?>
                  <span class="field__error"><?= e($errors['email']) ?></span>
                <?php endif; ?>
              </div>

              <!-- "When" is its own visible band in the form: on a phone the
                   date and time sat in the middle of a run of identical
                   fields and read as part of the contact details. -->
              <p class="form-grid__legend">
                <span class="form-grid__legend-text">When do you want it?</span>
                <span class="form-grid__legend-rule" aria-hidden="true"></span>
              </p>

              <div class="field<?= $bad('order_date') ?>">
                <label class="field__label" for="order_date">Date<span class="field__req" title="required" aria-label="required">*</span></label>
                <input class="field__input" type="date" id="order_date" name="order_date"
                       value="<?= e($old['order_date']) ?>" required>
                <?php if (!empty($errors['order_date'])): ?>
                  <span class="field__error"><?= e($errors['order_date']) ?></span>
                <?php endif; ?>
              </div>

              <div class="field<?= $bad('order_time') ?>">
                <label class="field__label" for="order_time">Time<span class="field__req" title="required" aria-label="required">*</span></label>
                <select class="field__select" id="order_time" name="order_time">
                  <?php foreach ($site['order_times'] as $value => $label): ?>
                    <?php
                      $slotAt  = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $old['order_date'] . ' ' . $value);
                      $tooSoon = $slotAt && $slotAt < $readyFrom;
                    ?>
                    <option value="<?= e($value) ?>"
                            <?= $old['order_time'] === $value ? 'selected' : '' ?>
                            <?= $tooSoon ? 'disabled' : '' ?>>
                      <?= e($label) ?><?= $tooSoon ? ' — too soon' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['order_time'])): ?>
                  <span class="field__error"><?= e($errors['order_time']) ?></span>
                <?php endif; ?>
              </div>

              <p class="form-aside">
                The kitchen needs <?= (int) $rules['lead_minutes'] ?> minutes, so the
                earliest slots may be greyed out. We will call you if anything changes.
              </p>

              <div class="field field--full<?= $bad('notes') ?>">
                <label class="field__label" for="notes">Notes <span class="field__hint">(optional)</span></label>
                <textarea class="field__textarea field__textarea--short" id="notes" name="notes"
                          maxlength="500" placeholder="No chilli, extra napkins, a birthday…"><?= e($old['notes']) ?></textarea>
                <span class="field__counter" data-counter-for="notes"></span>
                <?php if (!empty($errors['notes'])): ?>
                  <span class="field__error"><?= e($errors['notes']) ?></span>
                <?php endif; ?>
              </div>

              <!-- Only for delivery. The Collection/Delivery switch lives on
                   the summary card; script.js shows and hides this to match,
                   and order.php checks it again on the server. -->
              <div class="field field--full<?= $bad('address') ?>" data-address-field
                   <?= $old['order_type'] === 'collection' ? 'hidden' : '' ?>>
                <label class="field__label" for="address">Delivery address<span class="field__req" title="required" aria-label="required">*</span></label>
                <textarea class="field__textarea field__textarea--short" id="address" name="address"
                          maxlength="255" placeholder="Street, building, flat"><?= e($old['address']) ?></textarea>
                <?php if (!empty($errors['address'])): ?>
                  <span class="field__error"><?= e($errors['address']) ?></span>
                <?php endif; ?>
              </div>

            </div>

            <!-- This is the button that actually places the order. -->
            <footer class="details-card__foot">
              <p class="details-card__note">
                <span data-details-count><?= count($order['lines']) ?></span>
                <span data-details-word>item<?= count($order['lines']) === 1 ? '' : 's' ?></span>
                in your order &middot;
                <strong>KSh <span data-order-total-plain><?= number_format($orderTotal, 0) ?></span></strong>
              </p>
              <button class="button button--lg details-card__go" type="submit" name="action" value="order">
                Continue to payment
              </button>
              <!-- Says what the button does before it is pressed. "Place the
                   order" followed by an unexpected payment screen reads as a
                   trap; this way nothing is a surprise. -->
              <p class="details-card__after">
                Next: choose how to pay. Nothing is charged until you do.
              </p>
            </footer>
          </section>

        </div>

        <!-- ========== right: the receipt ========== -->
        <aside class="checkout__side">
          <div class="receipt-panel">

            <!-- the address block -->
            <div class="receipt-panel__top">
              <p class="receipt-panel__label" data-where-label>
                <?= $old['order_type'] === 'delivery' ? 'Delivery address' : 'Collection' ?>
              </p>
              <p class="rp-line">
                <svg class="rp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>
                </svg>
                <span data-where-summary data-pickup="<?= e($site['address']) ?>">
                  <?= $old['order_type'] === 'delivery'
                        ? ($old['address'] !== '' ? e($old['address']) : 'Add your address below')
                        : e($site['address']) ?>
                </span>
              </p>
              <p class="rp-line rp-line--muted">
                <svg class="rp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
                </svg>
                <span><?= (int) $rules['lead_minutes'] ?> min</span>
              </p>
            </div>

            <div class="receipt-panel__body">

              <div class="receipt-panel__bar">
                <h2 class="receipt-panel__title">
                  <svg class="rp-icon rp-icon--lg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 4h2l2.4 11h9.9l2.2-8H6.2"/><circle cx="9.5" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>
                  </svg>
                  Cart
                </h2>
                <span class="receipt-panel__count"><span data-tray-count><?= count($order['lines']) ?></span> items</span>
              </div>

              <fieldset class="seg">
                <legend class="visually-hidden">Collection or delivery</legend>
                <label class="seg__opt">
                  <input type="radio" name="order_type" value="collection"
                         <?= $old['order_type'] === 'collection' ? 'checked' : '' ?> data-order-type>
                  <span>Collection</span>
                </label>
                <label class="seg__opt">
                  <input type="radio" name="order_type" value="delivery"
                         <?= $old['order_type'] === 'delivery' ? 'checked' : '' ?> data-order-type>
                  <span>Delivery</span>
                </label>
              </fieldset>

              <p class="receipt-panel__empty" data-tray-empty<?= $order['lines'] ? ' hidden' : '' ?>>
                Your cart is empty. Pick something from the menu.
              </p>

              <ul class="cart" data-tray-list>
                <?php foreach ($order['lines'] as $line): ?>
                  <?php $lp = trim((string) ($line['image'] ?? '')); ?>
                  <li class="cart__row">
                    <?php if ($lp !== '' && is_file(__DIR__ . '/images/' . basename($lp))): ?>
                      <img class="cart__thumb" src="images/<?= e(basename($lp)) ?>" alt="" width="48" height="48">
                    <?php else: ?>
                      <span class="cart__thumb cart__thumb--none" aria-hidden="true">
                        <?= e(mb_strtoupper(mb_substr($line['name'], 0, 1))) ?>
                      </span>
                    <?php endif; ?>

                    <div class="cart__text">
                      <p class="cart__name"><?= e($line['name']) ?></p>
                      <p class="cart__desc"><?= e(mb_strimwidth((string) $line['description'], 0, 34, '…')) ?></p>
                      <p class="cart__qty"><?= (int) $line['quantity'] ?> &times; KSh <?= number_format($line['unit_price'], 0) ?></p>
                    </div>

                    <span class="cart__money">KSh <?= number_format($line['quantity'] * $line['unit_price'], 0) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="receipt-panel__tear" aria-hidden="true"></div>

            <div class="receipt-panel__foot">

              <p class="rp-chiprow">
                <span class="rp-chiprow__label">Ready at</span>
                <span class="rp-chiprow__chip" data-ready-chip>
                  <?= e($site['order_times'][$old['order_time']] ?? '') ?>
                </span>
              </p>

              <dl class="order-sum">
                <dt>Sub total</dt>
                <dd data-order-subtotal>KSh <?= number_format($order['subtotal'], 0) ?></dd>

                <dt class="order-sum__fee"<?= $deliveryFee > 0 ? '' : ' hidden' ?> data-fee-row>Delivery charge</dt>
                <dd class="order-sum__fee"<?= $deliveryFee > 0 ? '' : ' hidden' ?> data-fee-row>
                  KSh <?= number_format($rules['delivery_fee'], 0) ?>
                </dd>

                <dt class="order-sum__total">Total</dt>
                <dd class="order-sum__total" data-order-total>KSh <?= number_format($orderTotal, 0) ?></dd>
              </dl>

              <p class="order-note"
                 data-delivery-min="<?= (int) $rules['delivery_minimum'] ?>"
                 data-delivery-fee="<?= (int) $rules['delivery_fee'] ?>">
                Delivery costs KSh <?= number_format($rules['delivery_fee'], 0) ?>
                on orders over KSh <?= number_format($rules['delivery_minimum'], 0) ?>.
              </p>

              <!-- Takes you to the details form rather than placing the order.
                   A link, so it still works with JavaScript off; script.js
                   only adds the scrolling and puts the cursor in the first
                   empty field. -->
              <a class="button button--block receipt-panel__go" href="#details" data-to-details>
                Confirm order
              </a>
            </div>
          </div>
        </aside>

      </div>

    </form>
  </div>
</section>

<?php require __DIR__ . '/../src/footer.php'; ?>
