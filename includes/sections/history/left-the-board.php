<?php
/* What left the board: cancellations and deletions, newest first. A deleted
 * order has no row left, so this is its only record. */
?>
  <section class="kgone" aria-label="Removed from the board">
    <header class="kgone__head">
      <h2 class="kgone__title">Left the <span class="kgone__title-em">board</span></h2>
      <p class="kgone__sub">Last 24 hours &middot; <?= count($exits) ?> in all</p>
    </header>

    <ol class="kgone__list">
      <?php foreach ($exits as $x): ?>
        <?php $gone = $x['to_status'] === 'deleted'; ?>
        <li class="kgone__row kgone__row--<?= $gone ? 'gone' : 'off' ?>">
          <span class="kgone__dot" aria-hidden="true"></span>

          <span class="kgone__ref"><?= e($x['reference'] ?? '—') ?></span>

          <span class="kgone__what">
            <?php if ($gone): ?>
              Deleted for good
            <?php else: ?>
              Cancelled<?= $x['from_status'] === 'new' ? '' : ' while ' . e($x['from_status']) ?>
            <?php endif; ?>
          </span>

          <span class="kgone__who">by <?= e($x['actor']) ?></span>

          <time class="kgone__when" datetime="<?= e($x['created_at']) ?>">
            <?= e(date('D j M, g:i a', strtotime($x['created_at']))) ?>
          </time>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
