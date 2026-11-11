<?php
/* Everything this account has ordered. Included by order-status.php when
 * somebody is signed in and asked for no particular order. */
?>

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

<?php if (isset($_GET['deleted'])): ?>
  <p class="msg msg--ok">
    <span class="msg__icon" aria-hidden="true">&check;</span>
    <span>Order <strong><?= e((string) $_GET['deleted']) ?></strong> has been
          deleted. Nothing of it is left.</span>
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
                  $photo = dish_photo($line['image'] ?? null);
                ?>
                <?php if ($photo): ?>
                  <img class="myorder__thumb" src="images/<?= e($photo) ?>"
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
                  $photo = dish_photo($line['image'] ?? null);
                ?>
                <li class="mydish">
                  <?php if ($photo): ?>
                    <img class="mydish__photo" src="images/<?= e($photo) ?>"
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

              <?php /* Clearing your own history. Only an order that is finished
                     * with, and never one paid then cancelled — that still owes
                     * you a refund, and the row is the record of it. */ ?>
              <?php
                $rowDone    = in_array($row['status'], ['completed', 'cancelled'], true);
                $owesRefund = $row['status'] === 'cancelled' && $rowPaid;
              ?>
              <?php if ($rowDone && !$owesRefund): ?>
                <?php if (($_GET['remove'] ?? '') === $row['reference']): ?>
                  <span class="myorder__sure">
                    Delete <strong><?= e($row['reference']) ?></strong> for good?
                    <form method="post" action="order-status.php">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="reference" value="<?= e($row['reference']) ?>">
                      <button class="button button--small button--danger" type="submit"
                              name="action" value="delete-order">Yes, delete</button>
                    </form>
                    <a class="button button--small button--ghost" href="order-status.php">Keep it</a>
                  </span>
                <?php else: ?>
                  <a class="button button--small button--danger"
                     href="order-status.php?remove=<?= urlencode($row['reference']) ?>">Delete</a>
                <?php endif; ?>
              <?php endif; ?>
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
