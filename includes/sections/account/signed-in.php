<?php
/* Signed in: who you are, your orders and bookings, and the way out.
 * Included by account.php. */
?>

  <!-- ====================== signed in ====================== -->
  <?php
    // A few numbers worth showing.
    $mine   = orders_for_user((int) $user['id']);
    $live   = array_filter($mine, fn ($o) => in_array($o['status'], ['new','preparing','ready'], true));
    $spent  = array_sum(array_map(fn ($o) => (float) $o['total'],
                array_filter($mine, fn ($o) => $o['status'] !== 'cancelled')));
    $owing  = array_filter($mine, fn ($o) => $o['payment_status'] !== 'paid'
                                          && $o['status'] !== 'cancelled');
    $latest = $mine[0] ?? null;
  ?>

  <!-- The banner carries the identity, so the detail below it does not
       have to repeat the name in a heading as well as a row. -->
  <header class="me">
    <span class="me__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
    <div class="me__who">
      <p class="me__eyebrow">Signed in as</p>
      <h1 class="me__name"><?= e($user['name']) ?></h1>
      <p class="me__sub"><?= e($user['email']) ?></p>
    </div>
    <form class="me__out" method="post" action="account.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="button button--ghost button--small" type="submit" name="action" value="sign-out">
        Sign out
      </button>
    </form>
  </header>

  <ul class="mestats">
    <li class="mestat">
      <span class="mestat__n"><?= count($mine) ?></span>
      <span class="mestat__k"><?= count($mine) === 1 ? 'Order' : 'Orders' ?></span>
    </li>
    <li class="mestat <?= $live ? 'mestat--live' : '' ?>">
      <span class="mestat__n"><?= count($live) ?></span>
      <span class="mestat__k">In progress</span>
    </li>
    <li class="mestat <?= $owing ? 'mestat--owing' : '' ?>">
      <span class="mestat__n"><?= count($owing) ?></span>
      <span class="mestat__k">To pay</span>
    </li>
    <li class="mestat">
      <span class="mestat__n mestat__n--money">KSh <?= number_format($spent, 0) ?></span>
      <span class="mestat__k">Spent with us</span>
    </li>
  </ul>

  <div class="mecards">

    <!-- What is happening right now, if anything is. -->
    <section class="card mecard mecard--wide">
      <h2 class="mecard__title">Your latest order</h2>

      <?php if (!$latest): ?>
        <p class="mecard__empty">
          You have not ordered yet. The menu is a good place to start.
        </p>
        <a class="button" href="menu.php">See the menu</a>
      <?php else: ?>
        <?php
          $lDue  = DateTimeImmutable::createFromFormat(
                      'Y-m-d H:i:s', $latest['order_date'] . ' ' . $latest['order_time']);
          $lWord = [
              'new' => 'Order received', 'preparing' => 'Being cooked',
              'ready' => 'Ready', 'completed' => 'With you',
              'cancelled' => 'Cancelled',
          ][$latest['status']] ?? $latest['status'];
        ?>
        <p class="mecard__ref">
          <strong><?= e($latest['reference']) ?></strong>
          <span class="mytag mytag--<?= e($latest['status']) ?>"><?= e($lWord) ?></span>
        </p>
        <p class="mecard__line">
          <?= e(implode(', ', array_map(
              fn ($l) => $l['quantity'] . '× ' . $l['name'], $latest['items']))) ?>
        </p>
        <p class="mecard__line mecard__line--quiet">
          <?= e($lDue ? $lDue->format('j M, g:i a') : '') ?> &middot;
          <?= $latest['order_type'] === 'delivery' ? 'Delivery' : 'Collection' ?> &middot;
          KSh <?= number_format((float) $latest['total'], 0) ?>
        </p>
        <a class="button" href="order-status.php?ref=<?= urlencode($latest['reference']) ?>">
          Track this order
        </a>
      <?php endif; ?>
    </section>

    <!-- The details, as a card rather than a bare list. -->
    <section class="card mecard">
      <h2 class="mecard__title">Your details</h2>
      <dl class="medetails">
        <dt>Phone</dt>
        <dd><?= e($user['phone']) ?></dd>
        <dt>Email</dt>
        <dd><?= e($user['email']) ?></dd>
        <dt>Member since</dt>
        <dd><?= e(date('j M Y', strtotime($user['created_at']))) ?></dd>
      </dl>
      <p class="mecard__note">
        These fill in your order form. You can change them on any order
        without changing the account.
      </p>
    </section>

    <!-- Where to go next. -->
    <section class="card mecard mecard--go">
      <h2 class="mecard__title">What next</h2>
      <div class="melinks">
        <a class="melink" href="order.php">
          <span class="melink__k">Order food</span>
          <span class="melink__v">Your details are already filled in</span>
        </a>
        <a class="melink" href="order-status.php">
          <span class="melink__k">All my orders</span>
          <span class="melink__v"><?= count($mine) ?> on this account</span>
        </a>
        <a class="melink" href="reserve.php">
          <span class="melink__k">Book a table</span>
          <span class="melink__v">Eat in instead</span>
        </a>
      </div>
    </section>

  </div>
