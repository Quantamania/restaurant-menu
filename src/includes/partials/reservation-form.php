<?php
/**
 * Reservation page — the booking form.
 *
 * Available here:
 *   $old    - submitted values, to repopulate the form after an error. Keys:
 *             name, email, phone, reservation_date, party_size, message
 *   $errors - per-field messages keyed by field name; $errors['form'] holds
 *             form-level errors
 *   $flash  - success message after a completed booking, otherwise null
 *
 * REQUIRED, or every submission is rejected as an expired session:
 *   <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
 *
 * The form must POST to reserve.php. Repopulate each field from $old so the
 * visitor does not retype everything, and show $errors next to the field it
 * belongs to. Server-side validation already runs in reserve.php — any HTML5
 * validation you add is a convenience on top, never a replacement.
 *
 * Styles: public/css/reserve.css   Scripts: public/js/reserve.js
 */
?>
<section class="reservation">
  <h1 class="section-title">Reserve a table</h1>

  <?php if ($flash): ?>
    <p class="flash-success"><?= e($flash) ?></p>
  <?php endif; ?>

  <?php if (!empty($errors['form'])): ?>
    <p class="form-error"><?= e($errors['form']) ?></p>
  <?php endif; ?>

  <form method="post" action="reserve.php" class="reservation-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <!-- TODO: name, email, phone, reservation_date, party_size, message, submit -->
  </form>
</section>
