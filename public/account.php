<?php
/* ACCOUNT PAGE */

require __DIR__ . '/../src/setup.php';

$pageTitle = 'Your account';
$crumbs    = [['Home', 'index.php'], ['Your account', null]];

$errors  = [];          // field => message, for the register form
$signInError = null;    // one message for the sign-in form
$mode    = ($_GET['mode'] ?? $_POST['mode'] ?? '') === 'register' ? 'register' : 'signin';

// Where to go afterwards.
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
            // claim_orders_for_user() has left the number of past orders it attached in the session.
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
      <?php
        // A few numbers worth showing.
        $mine   = orders_for_user((int) $user['id']);
        $live   = array_filter($mine, fn ($o) => in_array($o['status'], ['new','preparing','ready'], true));
        $spent  = array_sum(array_map(fn ($o) => (float) $o['total'],
                    array_filter($mine, fn ($o) => $o['status'] !== 'cancelled')));
        $owing  = array_filter($mine, fn ($o) => $o['payment_status'] !== 'paid'
                                              && $o['status'] !== 'cancelled');
        $latest = $mine[0] ?? null;
      ?>

      <!-- The banner carries the identity, so the detail below it does not
           have to repeat the name in a heading as well as a row. -->
      <header class="me">
        <span class="me__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <div class="me__who">
          <p class="me__eyebrow">Signed in as</p>
          <h1 class="me__name"><?= e($user['name']) ?></h1>
          <p class="me__sub"><?= e($user['email']) ?></p>
        </div>
        <form class="me__out" method="post" action="account.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="button button--ghost button--small" type="submit" name="action" value="sign-out">
            Sign out
          </button>
        </form>
      </header>

      <ul class="mestats">
        <li class="mestat">
          <span class="mestat__n"><?= count($mine) ?></span>
          <span class="mestat__k"><?= count($mine) === 1 ? 'Order' : 'Orders' ?></span>
        </li>
        <li class="mestat <?= $live ? 'mestat--live' : '' ?>">
          <span class="mestat__n"><?= count($live) ?></span>
          <span class="mestat__k">In progress</span>
        </li>
        <li class="mestat <?= $owing ? 'mestat--owing' : '' ?>">
          <span class="mestat__n"><?= count($owing) ?></span>
          <span class="mestat__k">To pay</span>
        </li>
        <li class="mestat">
          <span class="mestat__n mestat__n--money">KSh <?= number_format($spent, 0) ?></span>
          <span class="mestat__k">Spent with us</span>
        </li>
      </ul>

      <div class="mecards">

        <!-- What is happening right now, if anything is. -->
        <section class="card mecard mecard--wide">
          <h2 class="mecard__title">Your latest order</h2>

          <?php if (!$latest): ?>
            <p class="mecard__empty">
              You have not ordered yet. The menu is a good place to start.
            </p>
            <a class="button" href="menu.php">See the menu</a>
          <?php else: ?>
            <?php
              $lDue  = DateTimeImmutable::createFromFormat(
                          'Y-m-d H:i:s', $latest['order_date'] . ' ' . $latest['order_time']);
              $lWord = [
                  'new' => 'Order received', 'preparing' => 'Being cooked',
                  'ready' => 'Ready', 'completed' => 'With you',
                  'cancelled' => 'Cancelled',
              ][$latest['status']] ?? $latest['status'];
            ?>
            <p class="mecard__ref">
              <strong><?= e($latest['reference']) ?></strong>
              <span class="mytag mytag--<?= e($latest['status']) ?>"><?= e($lWord) ?></span>
            </p>
            <p class="mecard__line">
              <?= e(implode(', ', array_map(
                  fn ($l) => $l['quantity'] . '× ' . $l['name'], $latest['items']))) ?>
            </p>
            <p class="mecard__line mecard__line--quiet">
              <?= e($lDue ? $lDue->format('j M, g:i a') : '') ?> &middot;
              <?= $latest['order_type'] === 'delivery' ? 'Delivery' : 'Collection' ?> &middot;
              KSh <?= number_format((float) $latest['total'], 0) ?>
            </p>
            <a class="button" href="order-status.php?ref=<?= urlencode($latest['reference']) ?>">
              Track this order
            </a>
          <?php endif; ?>
        </section>

        <!-- The details, as a card rather than a bare list. -->
        <section class="card mecard">
          <h2 class="mecard__title">Your details</h2>
          <dl class="medetails">
            <dt>Phone</dt>
            <dd><?= e($user['phone']) ?></dd>
            <dt>Email</dt>
            <dd><?= e($user['email']) ?></dd>
            <dt>Member since</dt>
            <dd><?= e(date('j M Y', strtotime($user['created_at']))) ?></dd>
          </dl>
          <p class="mecard__note">
            These fill in your order form. You can change them on any order
            without changing the account.
          </p>
        </section>

        <!-- Where to go next. -->
        <section class="card mecard mecard--go">
          <h2 class="mecard__title">What next</h2>
          <div class="melinks">
            <a class="melink" href="order.php">
              <span class="melink__k">Order food</span>
              <span class="melink__v">Your details are already filled in</span>
            </a>
            <a class="melink" href="order-status.php">
              <span class="melink__k">All my orders</span>
              <span class="melink__v"><?= count($mine) ?> on this account</span>
            </a>
            <a class="melink" href="reserve.php">
              <span class="melink__k">Book a table</span>
              <span class="melink__v">Eat in instead</span>
            </a>
          </div>
        </section>

      </div>

    <?php else: ?>

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

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/../src/footer.php'; ?>
