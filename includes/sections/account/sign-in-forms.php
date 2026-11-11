<?php
/* Signed out: the sign-in and create-account forms. Included by
 * account.php. */
?>

  <!-- ====================== signed out ======================
       Built to the reference: a dark panel, one narrow column, large
       filled fields with rounded corners, a full-width primary button,
       then the quieter alternatives underneath a rule.

       The labels are real <label>s that sit INSIDE the field and rise
       out of the way once there is something typed. That is the look in
       the reference — placeholder-as-label — without its cost, which is
       that a placeholder disappears the moment you start typing and
       takes the only description of the field with it. The movement is
       pure CSS (:placeholder-shown), so it needs no JavaScript. -->
  <div class="auth">

    <div class="auth__brandline">
      <?= brand_lockup($site['name'], 'brand--auth', 'auth') ?>
    </div>

    <h1 class="auth__title">
      <?= $mode === 'register'
          ? 'Create your ' . e($site['name']) . ' account'
          : 'Log into ' . e($site['name']) ?>
    </h1>

    <?php if (($_GET['why'] ?? '') === 'order'): ?>
      <p class="auth__why">
        Ordering needs an account, so we know where to send your food and
        you can follow it afterwards.
      </p>
    <?php endif; ?>

    <?php if ($mode === 'signin'): ?>

      <?php if ($signInError): ?>
        <p class="auth__error" role="alert">
          <span aria-hidden="true">!</span> <?= e($signInError) ?>
        </p>
      <?php endif; ?>

      <form class="auth__form" method="post" action="account.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <input type="hidden" name="mode" value="signin">

        <div class="af">
          <input class="af__input" type="email" id="si-email" name="email"
                 value="<?= e($old['email']) ?>" required autocomplete="email"
                 placeholder=" " autofocus>
          <label class="af__label" for="si-email">Email address</label>
        </div>

        <div class="af">
          <input class="af__input af__input--pw" type="password" id="si-password"
                 name="password" required autocomplete="current-password"
                 placeholder=" ">
          <label class="af__label" for="si-password">Password</label>
          <button class="af__reveal" type="button" data-pw-toggle="si-password"
                  aria-controls="si-password" aria-pressed="false" hidden>
            <span data-pw-label>Show</span>
          </button>
        </div>

        <button class="auth__go" type="submit" name="action" value="sign-in">
          Log in
        </button>
      </form>

      <p class="auth__aside">
        Forgotten your password? Call us on <?= e($site['phone']) ?> —
        we cannot reset it from here yet.
      </p>

      <div class="auth__or"><span>or</span></div>

      <a class="auth__alt" href="account.php?mode=register&amp;next=<?= urlencode($next) ?>">
        Create a new account
      </a>

    <?php else: ?>

      <?php if (!empty($errors['form'])): ?>
        <p class="auth__error" role="alert">
          <span aria-hidden="true">!</span> <?= e($errors['form']) ?>
        </p>
      <?php endif; ?>

      <form class="auth__form" method="post" action="account.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <input type="hidden" name="mode" value="register">

        <div class="auth__pair">
        <div class="af<?= !empty($errors['name']) ? ' is-bad' : '' ?>">
          <input class="af__input" type="text" id="rg-name" name="name"
                 value="<?= e($old['name']) ?>" required maxlength="100"
                 autocomplete="name" placeholder=" " autofocus>
          <label class="af__label" for="rg-name">Full name</label>
          <?php if (!empty($errors['name'])): ?>
            <span class="af__msg"><?= e($errors['name']) ?></span>
          <?php endif; ?>
        </div>

        <div class="af<?= !empty($errors['phone']) ? ' is-bad' : '' ?>">
          <input class="af__input" type="tel" id="rg-phone" name="phone"
                 value="<?= e($old['phone']) ?>" required autocomplete="tel"
                 placeholder=" ">
          <label class="af__label" for="rg-phone">Mobile number</label>
          <span class="af__msg">
            <?= !empty($errors['phone']) ? e($errors['phone'])
                : 'The kitchen rings this if there is a problem.' ?>
          </span>
        </div>
        </div><!-- /.auth__pair -->

        <div class="af<?= !empty($errors['email']) ? ' is-bad' : '' ?>">
          <input class="af__input" type="email" id="rg-email" name="email"
                 value="<?= e($old['email']) ?>" required maxlength="190"
                 autocomplete="email" placeholder=" ">
          <label class="af__label" for="rg-email">Email address</label>
          <span class="af__msg">
            <?= !empty($errors['email']) ? e($errors['email'])
                : 'This is what you log in with.' ?>
          </span>
        </div>

        <div class="auth__pair">
        <div class="af<?= !empty($errors['password']) ? ' is-bad' : '' ?>">
          <input class="af__input af__input--pw" type="password" id="rg-password"
                 name="password" required autocomplete="new-password"
                 minlength="<?= ACCOUNT_MIN_PASSWORD ?>" placeholder=" ">
          <label class="af__label" for="rg-password">Password</label>
          <button class="af__reveal" type="button" data-pw-toggle="rg-password"
                  aria-controls="rg-password" aria-pressed="false" hidden>
            <span data-pw-label>Show</span>
          </button>
          <span class="af__msg">
            <?= !empty($errors['password']) ? e($errors['password'])
                : 'At least ' . ACCOUNT_MIN_PASSWORD . ' characters.' ?>
          </span>
        </div>

        <div class="af<?= !empty($errors['confirm']) ? ' is-bad' : '' ?>">
          <input class="af__input af__input--pw" type="password" id="rg-confirm"
                 name="confirm" required autocomplete="new-password" placeholder=" ">
          <label class="af__label" for="rg-confirm">Repeat password</label>
          <button class="af__reveal" type="button" data-pw-toggle="rg-confirm"
                  aria-controls="rg-confirm" aria-pressed="false" hidden>
            <span data-pw-label>Show</span>
          </button>
          <?php if (!empty($errors['confirm'])): ?>
            <span class="af__msg"><?= e($errors['confirm']) ?></span>
          <?php endif; ?>
        </div>
        </div><!-- /.auth__pair -->

        <button class="auth__go" type="submit" name="action" value="register">
          Create account
        </button>
      </form>

      <p class="auth__aside">
        By creating an account you agree to let us ring or text you about
        your orders. Nothing else.
      </p>

      <div class="auth__or"><span>or</span></div>

      <a class="auth__alt" href="account.php?mode=signin&amp;next=<?= urlencode($next) ?>">
        I already have an account
      </a>

    <?php endif; ?>

  </div>
