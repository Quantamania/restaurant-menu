<?php
/* The customer's own bookings, on the reserve page: the dark cards, with
 * cancel and delete on each. */
?>
<section class="bpanel bpanel--mine" aria-label="Your bookings">
  <header class="bpanel__head">
    <h2 class="bpanel__title">
      Your <span class="bpanel__title-em">table</span>
    </h2>
    <p class="bpanel__count">
      <?php if ($upcoming): ?>
        <?= count($upcoming) ?> reserved &middot;
      <?php endif; ?>
      <?= count($myBookings) ?> shown
    </p>
  </header>

  <ul class="bgrid">
    <?php foreach ($myBookings as $b): ?>
      <?php
        $when      = strtotime($b['reservation_date'] . ' ' . $b['reservation_time']);
        $cancelled = $b['status'] === 'cancelled';
        $past      = $when < time();
        $seats     = (int) ($b['table_seats'] ?? 0);
      ?>
      <li class="bcard<?= $cancelled ? ' bcard--off' : ($past ? ' bcard--past' : '') ?>">
        <p class="bcard__time">
          <?= e(date('g:i', $when)) ?><span><?= e(date('a', $when)) ?></span>
        </p>

        <p class="bcard__name"><?= e(date('D j M Y', $when)) ?></p>

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

        <p class="bcard__contact"><?= e($b['name']) ?></p>

        <?php if ($cancelled): ?>
          <span class="bcard__chip bcard__chip--off">Cancelled</span>
        <?php elseif ($past): ?>
          <span class="bcard__chip bcard__chip--past">Passed</span>
        <?php endif; ?>

        <?php if ((int) ($_GET['remove'] ?? 0) === (int) $b['id']): ?>
          <div class="bcard__confirm">
            <p>Delete this booking?<?= !$cancelled && !$past ? ' The table goes back on sale.' : '' ?></p>
            <div class="bcard__confirm-acts">
              <form method="post" action="reserve.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reservation_id" value="<?= (int) $b['id'] ?>">
                <button class="button button--small button--danger" type="submit"
                        name="action" value="delete-booking">Yes, delete</button>
              </form>
              <a class="button button--small button--ghost" href="reserve.php">Keep it</a>
            </div>
          </div>
        <?php else: ?>
          <div class="bcard__acts">
            <?php if (!$cancelled && !$past): ?>
              <form method="post" action="reserve.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reservation_id" value="<?= (int) $b['id'] ?>">
                <button class="button button--small button--ghost" type="submit"
                        name="action" value="cancel-booking">Cancel</button>
              </form>
            <?php endif; ?>
            <a class="button button--small button--danger"
               href="reserve.php?remove=<?= (int) $b['id'] ?>">Delete</a>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <p class="bpanel__more">
    <a class="button button--small" href="#book">
      <?= $upcoming ? 'Book another table' : 'Book a table' ?>
    </a>
  </p>
</section>
