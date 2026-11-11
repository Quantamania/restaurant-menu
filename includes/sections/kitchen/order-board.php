<?php
/* The live board: one ticket per order, with the buttons that move it
 * through the stages. Included by kitchen.php. */
?>
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
                $photo = dish_photo($line['image'] ?? null);
              ?>
              <li>
                <!-- The photo, so a cook recognises the dish without
                     reading. The name stays: the photo is a shortcut,
                     not a replacement. -->
                <?php if ($photo): ?>
                  <img class="kticket__thumb" src="images/<?= e($photo) ?>"
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

        <?php /* Clearing the board: a finished order as well as a
               * cancelled one. delete_order() still refuses a live one,
               * and a cancelled order that was paid. */ ?>
        <?php if (in_array($order['status'], ['completed', 'cancelled'], true)): ?>
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
