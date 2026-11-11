<?php
/* The basket down the side: what has been chosen, the running total, and
 * the button that places the order. Included by order.php. */
?>
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
          <?php $lp = dish_photo($line['image'] ?? null); ?>
          <li class="cart__row">
            <?php if ($lp !== null): ?>
              <img class="cart__thumb" src="images/<?= e($lp) ?>" alt="" width="48" height="48">
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
