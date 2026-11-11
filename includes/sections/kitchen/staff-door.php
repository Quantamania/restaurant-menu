<?php
/* The staff door. Shown by kitchen.php when nobody is signed in — either
 * asking for the password, or explaining how to set one. */
?>

<!-- ====================== sign in ====================== -->
<main class="kgate">
  <div class="kgate__card">
    <!-- The same lockup as every other page. Even the locked door should
         look like it belongs to this restaurant. -->
    <div class="kgate__brand"><?= brand_lockup($site['name'], 'brand--kitchen', 'gate') ?></div>

    <h1 class="kgate__title">Kitchen</h1>

    <?php if (!staff_is_configured()): ?>

      <p class="kgate__lead">This screen has not been set up yet.</p>

      <?php if (staff_request_is_local()): ?>
        <!-- Only on a development machine. On a real host the fix is not
             the visitor's business, and naming the file would be telling
             an attacker exactly what to go looking for. -->
        <ol class="kgate__steps">
          <li>Copy the template:<br><code>cp includes/config.example.php includes/config.php</code></li>
          <li>Make a password:<br><code>php tools/staff-password.php</code></li>
          <li>Paste the line it prints into the <code>staff</code> block of
              <code>includes/config.php</code>, then reload this page.</li>
        </ol>
      <?php else: ?>
        <p class="kgate__lead">Ask whoever set up the site.</p>
      <?php endif; ?>

    <?php elseif (staff_is_locked_out()): ?>

      <p class="msg msg--bad">
        <span class="msg__icon" aria-hidden="true">!</span>
        <span>Too many wrong tries from this device.
          Try again in <?= staff_lock_minutes_left() ?> minutes.</span>
      </p>

    <?php else: ?>

      <p class="kgate__lead">Staff only.</p>

      <?php if ($error): ?>
        <p class="msg msg--bad">
          <span class="msg__icon" aria-hidden="true">!</span>
          <span><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <form method="post" action="kitchen.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="field">
          <label class="field__label" for="password">Password</label>
          <input class="field__input" type="password" id="password" name="password"
                 required autofocus autocomplete="current-password">
        </div>

        <button class="button button--block" type="submit" name="action" value="sign-in">
          Sign in
        </button>
      </form>

    <?php endif; ?>
  </div>
</main>
