<?php
/* The booking form: who, when, how many, and the room's tables. */
?>
<form id="book" method="post" action="reserve.php" class="reservation-form" novalidate>

  <!-- Proves the form came from our page. Never remove this. -->
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <div class="reserve__layout">

    <!-- ========== the stub: fills in as they choose ========== -->
    <aside class="stub">
      <div class="stub__body">
        <p class="stub__brand"><?= e($site['name']) ?></p>
        <h2 class="stub__title">Table booking</h2>

        <dl class="stub__facts">
          <dt>Date</dt>
          <dd data-stub="date"><?= e(date('j M Y', strtotime($old['reservation_date']))) ?></dd>

          <dt>Seating</dt>
          <dd data-stub="time"><?= e($site['slots'][$old['reservation_time']]) ?></dd>

          <dt>Guests</dt>
          <dd data-stub="party"><?= $party > 0 ? (int) $party : '&mdash;' ?></dd>
        </dl>

        <div class="stub__table<?= $chosen ? '' : ' stub__table--none' ?>" data-stub="table">
          <?php if ($chosen): ?>
            <span class="stub__table-label"><?= e($chosen['label']) ?></span>
            <span class="stub__table-area"><?= e($chosen['area']) ?> &middot; <?= (int) $chosen['seats'] ?> seats</span>
          <?php else: ?>
            <span class="stub__table-label">&mdash;</span>
            <span class="stub__table-area">No table chosen yet</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="stub__tear" aria-hidden="true"></div>

      <div class="stub__foot">
        <!-- Takes you down to the details form rather than booking. A
             link, so it still works with JavaScript off; script.js adds
             the sliding and puts the cursor in the first empty field. -->
        <a class="button button--block" href="#your-details" data-to-details>
          Confirm booking
        </a>
      </div>
    </aside>

    <!-- ========== the steps ========== -->
    <div class="reserve__steps">

      <section class="card step-card">
        <p class="step"><span class="step__n">1</span> When are you coming?</p>

        <div class="field-row">
          <div class="field<?= $bad('reservation_date') ?>">
            <label class="field__label" for="reservation_date">Date<span class="field__req" title="required" aria-label="required">*</span></label>
            <input class="field__input" type="date" id="reservation_date" name="reservation_date"
                   value="<?= e($old['reservation_date']) ?>" required data-reload>
            <?php if (!empty($errors['reservation_date'])): ?>
              <span class="field__error"><?= e($errors['reservation_date']) ?></span>
            <?php endif; ?>
          </div>

          <div class="field">
            <label class="field__label" for="reservation_time">Seating<span class="field__req" title="required" aria-label="required">*</span></label>
            <select class="field__select" id="reservation_time" name="reservation_time" data-reload>
              <?php foreach ($site['slots'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $old['reservation_time'] === $value ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field<?= $bad('party_size') ?>">
          <label class="field__label" for="party_size">Party size<span class="field__req" title="required" aria-label="required">*</span></label>
          <select class="field__select" id="party_size" name="party_size" required data-reload>
            <option value="">Choose…</option>
            <?php for ($i = 1; $i <= 20; $i++): ?>
              <option value="<?= $i ?>" <?= (string) $old['party_size'] === (string) $i ? 'selected' : '' ?>>
                <?= $i ?> <?= $i === 1 ? 'person' : 'people' ?>
              </option>
            <?php endfor; ?>
          </select>
          <?php if (!empty($errors['party_size'])): ?>
            <span class="field__error"><?= e($errors['party_size']) ?></span>
          <?php endif; ?>
        </div>

        <noscript>
          <button class="button button--block" type="submit" name="action" value="check">
            Check availability
          </button>
        </noscript>
      </section>

      <section class="card step-card">
        <p class="step"><span class="step__n">2</span> Pick your table</p>

        <?php if (!empty($errors['table_id'])): ?>
          <p class="msg msg--bad">
            <span class="msg__icon" aria-hidden="true">!</span>
            <span><?= e($errors['table_id']) ?></span>
          </p>
        <?php endif; ?>

        <p class="tables__legend">
          <span class="dot dot--free"></span> Free
          <span class="dot dot--taken"></span> Booked
          <?php if ($party > 0): ?>
            <span class="dot dot--small"></span> Too small for <?= (int) $party ?>
          <?php endif; ?>
        </p>

        <?php if (!$tables): ?>
          <p class="msg msg--empty">No tables have been set up yet.</p>
        <?php else: ?>
          <?php foreach ($byArea as $areaName => $areaTables): ?>
            <?php $freeHere = count(array_filter($areaTables, fn($t) => !$t['is_taken'])); ?>
            <div class="area">
              <h3 class="area__name">
                <?= e($areaName) ?>
                <span class="area__free"><?= $freeHere ?> free</span>
              </h3>

              <ul class="tables">
                <?php foreach ($areaTables as $t): ?>
                  <?php
                    $taken    = (bool) $t['is_taken'];
                    $tooSmall = $party > 0 && (int) $t['seats'] < $party;
                    $blocked  = $taken || $tooSmall;
                    $state    = $taken ? 'taken' : ($tooSmall ? 'small' : 'free');
                  ?>
                  <li>
                    <input class="table-card__input" type="radio" name="table_id"
                           id="table-<?= (int) $t['id'] ?>" value="<?= (int) $t['id'] ?>"
                           data-label="<?= e($t['label']) ?>"
                           data-area="<?= e($t['area']) ?>"
                           data-seats="<?= (int) $t['seats'] ?>"
                           <?= $blocked ? 'disabled' : '' ?>
                           <?= (string) $old['table_id'] === (string) $t['id'] && !$blocked ? 'checked' : '' ?>>
                    <label class="table-card table-card--<?= $state ?>" for="table-<?= (int) $t['id'] ?>">
                      <span class="table-card__label"><?= e($t['label']) ?></span>
                      <span class="table-card__seats"><?= (int) $t['seats'] ?> seats</span>
                      <span class="table-card__state">
                        <?= $taken ? 'Booked' : ($tooSmall ? 'Too small' : 'Free') ?>
                      </span>
                    </label>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="card step-card form-brown" id="your-details">
        <p class="step"><span class="step__n">3</span> Your details</p>

        <div class="field-row">
          <div class="field<?= $bad('name') ?>">
            <label class="field__label" for="name">Name<span class="field__req" title="required" aria-label="required">*</span></label>
            <input class="field__input" type="text" id="name" name="name"
                   value="<?= e($old['name']) ?>" placeholder="Your full name" required maxlength="100">
            <?php if (!empty($errors['name'])): ?>
              <span class="field__error"><?= e($errors['name']) ?></span>
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
        </div>

        <div class="field<?= $bad('phone') ?>">
          <label class="field__label" for="phone">Phone <span class="field__hint">(optional)</span></label>
          <input class="field__input" type="tel" id="phone" name="phone"
                 value="<?= e($old['phone']) ?>" placeholder="+254 700 000 000">
          <?php if (!empty($errors['phone'])): ?>
            <span class="field__error"><?= e($errors['phone']) ?></span>
          <?php endif; ?>
        </div>

        <div class="field<?= $bad('message') ?>">
          <label class="field__label" for="message">Message <span class="field__hint">(optional)</span></label>
          <textarea class="field__textarea" id="message" name="message" maxlength="500"
                    placeholder="Allergies, a window seat, a birthday…"><?= e($old['message']) ?></textarea>
          <span class="field__counter" data-counter-for="message"></span>
          <?php if (!empty($errors['message'])): ?>
            <span class="field__error"><?= e($errors['message']) ?></span>
          <?php endif; ?>
        </div>

        <!-- This is the button that actually books the table.
             $table is only set while handling a POST, so it is read
             defensively: on a fresh visit there is no booking in
             progress and nothing to name. -->
        <?php $picked = $table ?? null; ?>
        <footer class="form-foot">
          <p class="form-foot__note">
            <?php if ($picked): ?>
              Table <strong><?= e($picked['label']) ?></strong> &middot;
              <?= e($site['slots'][$old['reservation_time']] ?? $old['reservation_time']) ?>
            <?php else: ?>
              Choose your table in step 2, then book here.
            <?php endif; ?>
          </p>
          <button class="button button--lg" type="submit" name="action" value="book">
            Confirm booking
          </button>
        </footer>
      </section>

    </div>
  </div>

</form>
