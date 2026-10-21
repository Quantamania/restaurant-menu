</main>
<?php
/* The bottom of every page: contact details, hours, and the script tag. */
?>
<?php
/* The full footer belongs to the landing page. */
?>
<?php if ($isLanding): ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="site-footer__grid">

      <!-- The brand column is wider than the other two: it carries a
           sentence, they carry lists. Equal columns would leave the tagline
           cramped and the hours swimming. -->
      <div class="fcol fcol--brand">
        <h2 class="fcol__brand"><?= wordmark($site['name']) ?></h2>
        <p class="fcol__tagline"><?= e($site['tagline']) ?></p>
      </div>

      <div class="fcol">
        <h3 class="fcol__head">Find us</h3>
        <ul class="fcol__list">
          <li><?= e($site['address']) ?></li>
          <li>
            <a href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>">
              <?= e($site['phone']) ?>
            </a>
          </li>
          <li>
            <a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a>
          </li>
        </ul>
      </div>

      <div class="fcol">
        <h3 class="fcol__head">Opening hours</h3>
        <!-- Days left, times right, on one baseline grid so the times line
             up in a column of their own. -->
        <dl class="fhours">
          <?php foreach ($site['hours'] as $days => $time): ?>
            <div class="fhours__row">
              <dt><?= e($days) ?></dt>
              <dd><?= e($time) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>

    </div>

    <p class="site-footer__base">
      <span>&copy; <?= date('Y') ?> <?= e($site['name']) ?>. All rights reserved.</span>
      <span class="site-footer__made">Cooked fresh, every day</span>
    </p>

    <!-- The name as a watermark across the foot of the page: no panel behind
         it, the same Poppins and bar-E as the logo (wordmark() draws both),
         and a brown dark enough to read against the teal without turning
         into a headline. aria-hidden because the name has already been
         announced twice by now — this is texture, not information. -->
    <div class="site-footer__mark" aria-hidden="true">
      <?= wordmark($site['name'], 'site-footer__word') ?>
    </div>
  </div>
</footer>
<?php endif; ?>

</div><!-- /.shell -->

<script src="script.js"></script>
</body>
</html>
