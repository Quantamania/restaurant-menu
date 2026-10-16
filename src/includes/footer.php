</main>
<?php
/**
 * Site footer, and the scripts for every page.
 *
 * Available here:
 *   $site - site settings (name, address, phone, email, hours)
 *
 * Build: contact details, opening hours summary, social links, copyright.
 * Escape every printed value with e().
 */
?>
<footer class="site-footer">
  <!-- TODO: contact details, hours summary, copyright -->
</footer>
<script src="js/main.js"></script>
<?php if (!empty($pageScript)): ?>
<script src="js/<?= e($pageScript) ?>.js"></script>
<?php endif; ?>
</body>
</html>
