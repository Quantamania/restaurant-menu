<?php
/* Orders that are finished with: collected, or called off. */
?>

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
                $photo = dish_photo($line['image'] ?? null);
              ?>
              <li class="kticket__line">
                <?php if ($photo): ?>
                  <img class="kticket__thumb" src="images/<?= e($photo) ?>"
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
