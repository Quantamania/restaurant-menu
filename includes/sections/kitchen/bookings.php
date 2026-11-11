<?php
/* The bookings view of the kitchen screen: tables booked, grouped by day.
 * Included by kitchen.php. */
?>

<!-- A dark panel, so bookings read as a different kind of thing from
     the orders board. The cards, the pale-teal labels and the
     label/value split with a rule between are the home page's
     opening-hours block, reused. -->
<section class="bpanel">
  <header class="bpanel__head">
    <h2 class="bpanel__title">
      Table <span class="bpanel__title-em">bookings</span>
    </h2>
    <p class="bpanel__count">
      <?= $bookingsDue ?> upcoming &middot;
      <?= count($bookings) ?> shown
    </p>
  </header>

  <?php if (!$bookings): ?>
    <p class="bpanel__empty">
      <?= $bScope === 'upcoming'
          ? 'Nothing booked yet. New bookings land here the moment they are made.'
          : 'No bookings to show.' ?>
    </p>
  <?php else: ?>

    <?php
      $lastDay = null;
      foreach ($bookings as $b):
        $when = DateTimeImmutable::createFromFormat(
                  'Y-m-d H:i:s', $b['reservation_date'] . ' ' . $b['reservation_time']);
        $day      = $when ? $when->format('Y-m-d') : '';
        $isToday  = $day === $now->format('Y-m-d');
        $isPast   = $when && $when < $now;
        $minsAway = $when ? (int) round(($when->getTimestamp() - $now->getTimestamp()) / 60) : null;
        $soon     = $minsAway !== null && $minsAway >= 0 && $minsAway <= 90;
        $party    = (int) ($b['party_size'] ?? 0);
        $seats    = (int) ($b['table_seats'] ?? 0);
    ?>
      <?php if ($day !== $lastDay): ?>
        <?php if ($lastDay !== null): ?></ul><?php endif; ?>
        <?php $lastDay = $day; ?>
        <h3 class="bday">
          <span class="bday__name"><?= $isToday ? 'Today' : e($when ? $when->format('l') : '—') ?></span>
          <span class="bday__rule" aria-hidden="true"></span>
          <span class="bday__date"><?= e($when ? $when->format('j M') : '') ?></span>
        </h3>
        <ul class="bgrid">
      <?php endif; ?>

      <li class="bcard<?= $isPast ? ' bcard--past' : ($soon ? ' bcard--soon' : '') ?>">
        <p class="bcard__time">
          <?= e($when ? $when->format('g:i') : '—') ?><span><?= e($when ? $when->format('a') : '') ?></span>
        </p>

        <p class="bcard__name"><?= e($b['name']) ?></p>

        <!-- Table and party, split by a rule: the home page's
             Opens / Closes pair, doing a different job. -->
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
            <span class="bcard__value"><?= $party ?: '—' ?></span>
            <?php if ($seats > 0): ?>
              <span class="bcard__sub">of <?= $seats ?> seats</span>
            <?php endif; ?>
          </span>
        </div>

        <p class="bcard__contact">
          <?php if (!empty($b['phone'])): ?>
            <a href="tel:<?= e(preg_replace('/\s+/', '', $b['phone'])) ?>"><?= e($b['phone']) ?></a>
          <?php endif; ?>
          <a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a>
        </p>

        <?php if (!empty($b['message'])): ?>
          <p class="bcard__note"><?= nl2br(e($b['message'])) ?></p>
        <?php endif; ?>

        <?php if ($isPast): ?>
          <span class="bcard__chip bcard__chip--past">Passed</span>
        <?php elseif ($soon): ?>
          <span class="bcard__chip bcard__chip--soon">in <?= $minsAway ?> min</span>
        <?php endif; ?>
        <?php if ($b['status'] === 'cancelled'): ?>
          <span class="bcard__chip bcard__chip--off">Cancelled</span>
        <?php endif; ?>

        <?php /* Clearing the board. Two presses: the question is asked on
               * the card itself, naming the table and the day. */ ?>
        <?php if ((int) ($_GET['bconfirm'] ?? 0) === (int) $b['id']): ?>
          <div class="bcard__confirm">
            <p>
              Remove <strong><?= e($b['table_label'] ?? '—') ?></strong>
              on <strong><?= e($when ? $when->format('D j M') : '—') ?></strong>?
              <?php if (!$isPast && $b['status'] !== 'cancelled'): ?>
                The table goes back on sale.
              <?php endif; ?>
            </p>
            <div class="bcard__confirm-acts">
              <form method="post" action="kitchen.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reservation_id" value="<?= (int) $b['id'] ?>">
                <input type="hidden" name="bscope" value="<?= e($bScope) ?>">
                <button class="button button--small button--danger" type="submit"
                        name="action" value="delete-booking">Yes, remove</button>
              </form>
              <a class="button button--small button--ghost"
                 href="kitchen.php?view=bookings&amp;bscope=<?= e($bScope) ?>">Keep</a>
            </div>
          </div>
        <?php else: ?>
          <p class="bcard__clear">
            <a class="button button--small button--danger"
               href="kitchen.php?view=bookings&amp;bscope=<?= e($bScope) ?>&amp;bconfirm=<?= (int) $b['id'] ?>">Remove</a>
          </p>
        <?php endif; ?>
      </li>

    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
