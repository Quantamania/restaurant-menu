</main>
<?php
/* ===========================================================================
 * The bottom of every page: contact details, hours, and the script tag.
 * =========================================================================== */
?>
<?php
/* The full footer belongs to the landing page. The inner pages end on their
   own content — their contact details are a tap away in the rail. */
?>
<?php if ($isLanding): ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="site-footer__grid">

      <div>
        <h3 class="site-footer__brand"><?= wordmark($site['name']) ?></h3>
        <p><?= e($site['tagline']) ?></p>
      </div>

      <div>
        <h3>Find us</h3>
        <address class="contact-item__value">
          <?= e($site['address']) ?><br>
          <a href="tel:<?= e(preg_replace('/\s+/', '', $site['phone'])) ?>"><?= e($site['phone']) ?></a><br>
          <a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a>
        </address>
      </div>

      <div>
        <h3>Opening hours</h3>
        <dl class="site-footer__hours">
          <?php foreach ($site['hours'] as $days => $time): ?>
            <dt><?= e($days) ?></dt>
            <dd><?= e($time) ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>

    </div>

    <p class="site-footer__base">
      &copy; <?= date('Y') ?> <?= e($site['name']) ?>. All rights reserved.
    </p>
  </div>
</footer>
<?php endif; ?>

</div><!-- /.shell -->

<script src="script.js"></script>
</body>
</html>
