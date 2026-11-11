<?php
/* Bookings that have been and gone, or were cancelled. */
?>

  <section class="bpanel">
    <header class="bpanel__head">
      <h2 class="bpanel__title">Past <span class="bpanel__title-em">bookings</span></h2>
      <p class="bpanel__count"><?= count($bookings) ?> shown</p>
    </header>

    <?php if (!$bookings): ?>
      <p class="kempty">Nothing here yet. Bookings move across once the day has passed, or when they are cancelled.</p>
    <?php else: ?>
      <ul class="bgrid">
        <?php foreach ($bookings as $b): ?>
          <?php
            $when      = strtotime($b['reservation_date'] . ' ' . $b['reservation_time']);
            $cancelled = $b['status'] === 'cancelled';
            $seats     = (int) ($b['table_seats'] ?? 0);
          ?>
          <li class="bcard <?= $cancelled ? 'bcard--off' : 'bcard--past' ?>">
            <p class="bcard__time">
              <?= e(date('g:i', $when)) ?><span><?= e(date('a', $when)) ?></span>
            </p>

            <p class="bcard__name"><?= e($b['name']) ?></p>
            <p class="bcard__contact"><?= e(date('D j M Y', $when)) ?></p>

            <div class="bcard__span">
              <span class="bcard__slot">
                <span class="bcard__label">Table</span>
                <span class="bcard__value"><?= e($b['table_label'] ?? '—') ?></span>
                <?php if (!empty($b['table_area'])): ?>
                  <span class="bcard__sub"><?= e($b['table_area']) ?></span>
                <?php endif; ?>
              </span>
              <span class="bcard__dash" aria-hidden="true"></span>
              <span class="bcard__slot">
                <span class="bcard__label">Party</span>
                <span class="bcard__value"><?= (int) $b['party_size'] ?: '—' ?></span>
                <?php if ($seats > 0): ?>
                  <span class="bcard__sub">of <?= $seats ?> seats</span>
                <?php endif; ?>
              </span>
            </div>

            <span class="bcard__chip <?= $cancelled ? 'bcard__chip--off' : 'bcard__chip--past' ?>">
              <?= $cancelled ? 'Cancelled' : 'Passed' ?>
            </span>

            <?php if ($confirmBooking === (int) $b['id']): ?>
              <div class="bcard__confirm">
                <p>Remove this booking for good?</p>
                <div class="bcard__confirm-acts">
                  <form method="post" action="history.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="reservation_id" value="<?= (int) $b['id'] ?>">
                    <button class="button button--small button--danger" type="submit"
                            name="action" value="delete-booking">Yes, remove</button>
                  </form>
                  <a class="button button--small button--ghost" href="history.php?view=bookings">Keep</a>
                </div>
              </div>
            <?php else: ?>
              <p class="bcard__clear">
                <a class="button button--small button--danger"
                   href="history.php?view=bookings&amp;bconfirm=<?= (int) $b['id'] ?>">Remove</a>
              </p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php /* ============================= past orders ============================= */ ?>
