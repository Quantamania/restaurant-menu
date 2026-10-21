<?php
/* ===========================================================================
 * ACCOUNT PAGE
 *
 * Sign in, or create an account. Both on one page, because somebody who
 * cannot remember whether they have registered should not have to guess
 * which page to be on.
 *
 * Reached on the way to ordering: order.php sends anyone not signed in here
 * with ?next=order.php, and this page sends them back once they are.
 *
 * Part 1 (PHP)  - handle the two forms
 * Part 2 (HTML) - signed in: their details. Signed out: the two forms.
 * =========================================================================== */

require __DIR__ . '/../src/setup.php';

$pageTitle = 'Your account';
$crumbs    = [['Home', 'index.php'], ['Your account', null]];

$errors  = [];          // field => message, for the register form
$signInError = null;    // one message for the sign-in form
$mode    = ($_GET['mode'] ?? $_POST['mode'] ?? '') === 'register' ? 'register' : 'signin';

// Where to go afterwards. Checked against a list of our own pages: taking a
// path from the query string and redirecting to it unchecked is an open
// redirect, which turns this page into a convincing way to bounce somebody
// to a phishing site.
$allowedNext = ['order.php', 'order-status.php', 'menu.php', 'reserve.php', 'index.php'];
$next = in_array($_GET['next'] ?? $_POST['next'] ?? '', $allowedNext, true)
      ? ($_GET['next'] ?? $_POST['next'])
      : 'order-status.php';

$old = ['name' => '', 'email' => '', 'phone' => ''];

/* ------------------------------------------------------------- signing out */

if (($_POST['action'] ?? null) === 'sign-out') {
    if (csrf_valid($_POST['csrf'] ?? null)) {
        logout_account();
        header('Location: index.php');
        exit;
    }
}

/* -------------------------------------------------------------- signing in */

if (($_POST['action'] ?? null) === 'sign-in') {
    $mode = 'signin';

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $signInError = 'That form had expired. Please try again.';
    } else {
        $result = login_account((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));

        if ($result['ok']) {
            header('Location: ' . $next);
            exit;
        }

        $signInError  = $result['error'];
        $old['email'] = trim((string) ($_POST['email'] ?? ''));
    }
}

/* ------------------------------------------------------------- registering */

if (($_POST['action'] ?? null) === 'register') {
    $mode = 'register';

    foreach ($old as $key => $_) {
        $old[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'That form had expired. Please try again.';
    } else {
        $result = register_account(
            $old['name'], $old['email'], $old['phone'],
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm'] ?? '')
        );

        if ($result['ok']) {
            // claim_orders_for_user() has left the number of past orders it
            // attached in the session; the destination page says so.
            header('Location: ' . $next . (str_contains($next, '?') ? '&' : '?') . 'welcome=1');
            exit;
        }

        $errors = $result['errors'];
    }
}

$user = current_user();

require __DIR__ . '/../src/header.php';
?>

<section class="section account-page">
  <div class="wrap">

    <?php if ($user): ?>

      <!-- ====================== signed in ====================== -->
      <div class="section-head">
        <span class="section-eyebrow">Your account</span>
        <h1 class="section-title">Hello, <?= e(explode(' ', $user['name'])[0]) ?></h1>
      </div>

      <div class="card acct">
        <dl class="acct__facts">
          <dt>Name</dt>
          <dd><?= e($user['name']) ?></dd>
          <dt>Email</dt>
          <dd><?= e($user['email']) ?></dd>
          <dt>Phone</dt>
          <dd><?= e($user['phone']) ?></dd>
          <dt>Member since</dt>
          <dd><?= e(date('j M Y', strtotime($user['created_at']))) ?></dd>
        </dl>

        <p class="acct__note">
          Your orders are tied to this account, so tracking one is just a
          matter of being signed in — there is no code to wait for.
        </p>

        <div class="acct__acts">
          <a class="button" href="order-status.php">My orders</a>
          <a class="button button--ghost" href="order.php">Order food</a>
          <form method="post" action="account.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <button class="button button--ghost" type="submit" name="action" value="sign-out">
              Sign out
            </button>
          </form>
        </div>
      </div>

    <?php else: ?>

      <!-- ====================== signed out ======================
           No card and no panel: the fields sit directly on the page in one
           centred column. An account form is the whole content of the page,
           so a box around it only draws a second edge inside the first. -->
      <div class="authhead">
        <h1 class="authhead__title">
          <?= $mode === 'register' ? 'Create your account' : 'Welcome back' ?>
        </h1>
        <p class="authhead__lead">
          <?= $mode === 'register'
              ? 'It takes a moment, and then your orders are all in one place.'
              : 'Sign in to see your orders and to order again.' ?>
        </p>
      </div>

      <?php if (($_GET['why'] ?? '') === 'order'): ?>
        <p class="msg msg--info authmsg">
          <span class="msg__icon" aria-hidden="true">i</span>
          <span>Ordering needs an account, so we know where to send your food
                and you can follow it afterwards.</span>
        </p>
      <?php endif; ?>

      <!-- Two links that reload with ?mode=, not scripted tabs, so the
           switch works with JavaScript off. -->
      <div class="authswitch" role="tablist" aria-label="Sign in or register">
        <a class="authswitch__tab<?= $mode === 'signin' ? ' is-on' : '' ?>"
           href="account.php?mode=signin&amp;next=<?= urlencode($next) ?>"
           role="tab" aria-selected="<?= $mode === 'signin' ? 'true' : 'false' ?>">
          Sign in
        </a>
        <a class="authswitch__tab<?= $mode === 'register' ? ' is-on' : '' ?>"
           href="account.php?mode=register&amp;next=<?= urlencode($next) ?>"
           role="tab" aria-selected="<?= $mode === 'register' ? 'true' : 'false' ?>">
          Create an account
        </a>
      </div>

      <?php if ($mode === 'signin'): ?>

        <?php if ($signInError): ?>
          <p class="msg msg--bad authmsg">
            <span class="msg__icon" aria-hidden="true">!</span>
            <span><?= e($signInError) ?></span>
          </p>
        <?php endif; ?>

        <form class="authform" method="post" action="account.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="next" value="<?= e($next) ?>">
          <input type="hidden" name="mode" value="signin">

          <div class="authfield">
            <label class="authfield__label" for="si-email">Email</label>
            <input class="authfield__input" type="email" id="si-email" name="email"
                   value="<?= e($old['email']) ?>" required autocomplete="email"
                   placeholder="you@example.com" autofocus>
          </div>

          <div class="authfield">
            <label class="authfield__label" for="si-password">Password</label>
            <!-- The reveal button starts hidden and script.js switches it on,
                 so with no JavaScript there is no button that does nothing. -->
            <div class="authfield__pw">
              <input class="authfield__input" type="password" id="si-password"
                     name="password" required autocomplete="current-password">
              <button class="pwtoggle" type="button" data-pw-toggle="si-password"
                      aria-controls="si-password" aria-pressed="false" hidden>
                <span data-pw-label>Show</span>
              </button>
            </div>
          </div>

          <button class="button button--lg button--block authform__go"
                  type="submit" name="action" value="sign-in">
            Sign in
          </button>

          <p class="authform__alt">
            No account yet?
            <a href="account.php?mode=register&amp;next=<?= urlencode($next) ?>">Create one</a>.
          </p>
        </form>

      <?php else: ?>

        <?php if (!empty($errors['form'])): ?>
          <p class="msg msg--bad authmsg">
            <span class="msg__icon" aria-hidden="true">!</span>
            <span><?= e($errors['form']) ?></span>
          </p>
        <?php endif; ?>

        <form class="authform" method="post" action="account.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="next" value="<?= e($next) ?>">
          <input type="hidden" name="mode" value="register">

          <!-- Name and phone share a row on a wide screen and stack on a
               narrow one; everything else is full width, so the left edge of
               every field lines up whatever the width. -->
          <div class="authform__pair">
            <div class="authfield<?= !empty($errors['name']) ? ' is-bad' : '' ?>">
              <label class="authfield__label" for="rg-name">Name</label>
              <input class="authfield__input" type="text" id="rg-name" name="name"
                     value="<?= e($old['name']) ?>" required maxlength="100"
                     autocomplete="name" placeholder="Your full name" autofocus>
              <?php if (!empty($errors['name'])): ?>
                <span class="authfield__msg authfield__msg--bad"><?= e($errors['name']) ?></span>
              <?php endif; ?>
            </div>

            <div class="authfield<?= !empty($errors['phone']) ? ' is-bad' : '' ?>">
              <label class="authfield__label" for="rg-phone">Phone</label>
              <input class="authfield__input" type="tel" id="rg-phone" name="phone"
                     value="<?= e($old['phone']) ?>" required autocomplete="tel"
                     placeholder="0712 345 678">
              <span class="authfield__msg<?= !empty($errors['phone']) ? ' authfield__msg--bad' : '' ?>">
                <?= !empty($errors['phone']) ? e($errors['phone']) : 'The kitchen rings this if there is a problem.' ?>
              </span>
            </div>
          </div>

          <div class="authfield<?= !empty($errors['email']) ? ' is-bad' : '' ?>">
            <label class="authfield__label" for="rg-email">Email</label>
            <input class="authfield__input" type="email" id="rg-email" name="email"
                   value="<?= e($old['email']) ?>" required maxlength="190"
                   autocomplete="email" placeholder="you@example.com">
            <span class="authfield__msg<?= !empty($errors['email']) ? ' authfield__msg--bad' : '' ?>">
              <?= !empty($errors['email']) ? e($errors['email']) : 'This is what you sign in with.' ?>
            </span>
          </div>

          <div class="authform__pair">
            <div class="authfield<?= !empty($errors['password']) ? ' is-bad' : '' ?>">
              <label class="authfield__label" for="rg-password">Password</label>
              <div class="authfield__pw">
                <input class="authfield__input" type="password" id="rg-password"
                       name="password" required autocomplete="new-password"
                       minlength="<?= ACCOUNT_MIN_PASSWORD ?>">
                <button class="pwtoggle" type="button" data-pw-toggle="rg-password"
                        aria-controls="rg-password" aria-pressed="false" hidden>
                  <span data-pw-label>Show</span>
                </button>
              </div>
              <span class="authfield__msg<?= !empty($errors['password']) ? ' authfield__msg--bad' : '' ?>">
                <?= !empty($errors['password']) ? e($errors['password'])
                    : 'At least ' . ACCOUNT_MIN_PASSWORD . ' characters.' ?>
              </span>
            </div>

            <div class="authfield<?= !empty($errors['confirm']) ? ' is-bad' : '' ?>">
              <label class="authfield__label" for="rg-confirm">Repeat password</label>
              <div class="authfield__pw">
                <input class="authfield__input" type="password" id="rg-confirm"
                       name="confirm" required autocomplete="new-password">
                <button class="pwtoggle" type="button" data-pw-toggle="rg-confirm"
                        aria-controls="rg-confirm" aria-pressed="false" hidden>
                  <span data-pw-label>Show</span>
                </button>
              </div>
              <?php if (!empty($errors['confirm'])): ?>
                <span class="authfield__msg authfield__msg--bad"><?= e($errors['confirm']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <button class="button button--lg button--block authform__go"
                  type="submit" name="action" value="register">
            Create my account
          </button>

          <p class="authform__alt">
            Already registered?
            <a href="account.php?mode=signin&amp;next=<?= urlencode($next) ?>">Sign in</a>.
          </p>
        </form>

      <?php endif; ?>

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/../src/footer.php'; ?>
