<?php
/* ACCOUNT PAGE */

require __DIR__ . '/includes/setup.php';

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

require __DIR__ . '/includes/header.php';
?>

<section class="section account-page">
  <div class="wrap">

    <?php if ($user): ?>
    <?php require __DIR__ . '/includes/sections/account/signed-in.php'; ?>
    <?php else: ?>
    <?php require __DIR__ . '/includes/sections/account/sign-in-forms.php'; ?>
    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
