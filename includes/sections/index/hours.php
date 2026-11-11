<?php
/* Opening hours and how to reach the restaurant. */
?>
<section id="hours" class="section hours">
  <div class="wrap">

    <header class="hours__head">
      <p class="hero__kicker">Plan your visit</p>
      <h2 class="hours__title">Opening hours</h2>
    </header>

    <ul class="hours__grid">
      <?php foreach ($site['hours'] as $days => $time): ?>
        <?php
          // "7:00 am - 9:00 pm" splits into the two halves the card shows.
          $parts = preg_split('/\s*[-–—]\s*/u', (string) $time, 2);
          $opens  = trim($parts[0] ?? (string) $time);
          $closes = trim($parts[1] ?? '');
        ?>
        <li class="hours__card">
          <p class="hours__days"><?= e($days) ?></p>

          <?php if ($closes !== ''): ?>
            <div class="hours__span">
              <span class="hours__slot">
                <span class="hours__label">Opens</span>
                <span class="hours__time"><?= e($opens) ?></span>
              </span>
              <span class="hours__dash" aria-hidden="true"></span>
              <span class="hours__slot">
                <span class="hours__label">Closes</span>
                <span class="hours__time"><?= e($closes) ?></span>
              </span>
            </div>
          <?php else: ?>
            <p class="hours__time hours__time--single"><?= e($opens) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- where to find us, under the hours -->
    <ul class="hours__facts">
      <li class="hours__fact">
        <span class="hours__fact-label">Find us</span>
        <span class="hours__fact-value"><?= e($site['address']) ?></span>
      </li>
      <li class="hours__fact">
        <span class="hours__fact-label">Call us</span>
        <a class="hours__fact-value" href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>"><?= e($site['phone']) ?></a>
      </li>
      <li class="hours__fact">
        <span class="hours__fact-label">Kitchen needs</span>
        <span class="hours__fact-value"><?= (int) $site['ordering']['lead_minutes'] ?> minutes&rsquo; notice</span>
      </li>
    </ul>

  </div>
</section>
