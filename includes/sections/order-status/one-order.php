<?php
/* One order, tracked. Included by order-status.php when the visitor asked
 * for a reference they are allowed to see. */
?>

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

<?php if ($error): ?>
  <p class="msg msg--bad">
    <span class="msg__icon" aria-hidden="true">!</span>
    <span><?= e($error) ?></span>
  </p>
<?php endif; ?>

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

<?php /* Deleting a cancelled order. Same spot as the cancel box, so the
       * question is where the eye already is after clicking the link. */ ?>
<?php if (($_GET['remove'] ?? '') === $order['reference'] && $cancelled
       && $order['payment_status'] !== 'paid'): ?>

  <div class="cancelbox">
    <p class="cancelbox__ask">
      Delete <strong><?= e($order['reference']) ?></strong> for good?
      This removes the order and everything on it. We cannot bring it
      back, and it leaves the kitchen's board too.
    </p>
    <div class="cancelbox__acts">
      <form method="post" action="order-status.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="reference" value="<?= e($order['reference']) ?>">
        <button class="button button--danger" type="submit" name="action" value="delete-order">
          Yes, delete it
        </button>
      </form>
      <a class="button button--ghost"
         href="order-status.php?ref=<?= urlencode($order['reference']) ?>">Keep the record</a>
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

<?php /* Only once it is cancelled — there is nothing to delete before
       * that, and the button would sit next to Cancel as a trap. */ ?>
<?php if ($cancelled && ($_GET['remove'] ?? '') !== $order['reference']): ?>
  <?php if ($order['payment_status'] === 'paid'): ?>
    <p class="cancelline">
      This one was paid, so we keep the record for your refund.
      Call <?= e($site['phone']) ?> if you need anything.
    </p>
  <?php else: ?>
    <p class="cancelline">
      Finished with this one?
      <a href="order-status.php?ref=<?= urlencode($order['reference']) ?>&amp;remove=<?= urlencode($order['reference']) ?>">
        Delete this order
      </a>
    </p>
  <?php endif; ?>
<?php endif; ?>

<p class="status-again">
  <a class="button button--small" href="order.php">Order something else</a>
  <a class="button button--small button--ghost" href="menu.php">Back to the menu</a>
</p>
