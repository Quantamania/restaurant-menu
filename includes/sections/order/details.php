<?php
/* Who the order is for and when they want it — name, phone, collection or
 * delivery, the time slot. Included by order.php. */
?>
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
