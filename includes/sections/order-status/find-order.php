<?php
/* No account and no order in hand: sign in, or look one up by reference.
 * Included by order-status.php as the last branch. */
?>

<!-- ========== signed out ========== -->
<div class="section-head">
  <span class="section-eyebrow">Already ordered?</span>
  <h1 class="section-title">Sign in to see your orders</h1>
  <p class="section-lead">
    Your orders live on your account, so there is no reference to find
    and no code to wait for.
  </p>
</div>

<?php if (isset($_GET['deleted'])): ?>
  <p class="msg msg--ok">
    <span class="msg__icon" aria-hidden="true">&check;</span>
    <span>Order <strong><?= e((string) $_GET['deleted']) ?></strong> has been
          deleted. Nothing of it is left.</span>
  </p>
<?php endif; ?>

<?php if ($error): ?>
  <p class="msg msg--bad">
    <span class="msg__icon" aria-hidden="true">!</span>
    <span><?= e($error) ?></span>
  </p>
<?php endif; ?>

<p class="status-again status-again--lead">
  <a class="button button--lg" href="account.php?next=order-status.php">Sign in</a>
  <a class="button button--lg button--ghost"
     href="account.php?mode=register&amp;next=order-status.php">Create an account</a>
</p>

<!-- The old reference-and-code route, kept for orders placed before
     accounts existed: those have no user_id, so there is no account to
     match them against. Registering with the same email claims them
     automatically, which is the usual way back in; this is the
     fallback when the address differs. -->
<details class="oldway">
  <summary>Ordered before you had an account?</summary>

  <div class="card status-lookup">

  <?php if ($found && $sentVia !== null): ?>

    <!-- Step two: the code. -->
    <h2 class="status-lookup__title">Type the code we sent</h2>
    <p class="reserve__card-note">
      <?php if ($sentTo !== null): ?>
        We sent a six-digit code to <strong><?= e($sentTo) ?></strong>.
        It works for ten minutes.
      <?php else: ?>
        Type the six-digit code we sent you.
      <?php endif; ?>
    </p>

    <form method="post" action="order-status.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="reference" value="<?= e($reference) ?>">

      <div class="field">
        <label class="field__label" for="code">Six-digit code</label>
        <input class="field__input field__input--code" type="text" id="code" name="code"
               inputmode="numeric" autocomplete="one-time-code" maxlength="6"
               pattern="[0-9]*" placeholder="000000" required autofocus
               spellcheck="false">
        <span class="field__hint">We will never ask you for this on the phone.</span>
      </div>

      <button class="button button--block" type="submit" name="action" value="check-code">
        See my order
      </button>
    </form>

    <!-- Two forms, not two buttons in one. A button carries a single
         name/value pair, and that pair is already spoken for by
         "action" — so the channel has to travel as its own hidden
         field, in its own form. -->
    <div class="status-lookup__again">
      <form method="post" action="order-status.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="reference" value="<?= e($reference) ?>">
        <input type="hidden" name="channel" value="sms">
        <button class="linkbtn" type="submit" name="action" value="send-code">
          Text it again
        </button>
      </form>

      <span class="status-lookup__sep" aria-hidden="true">&middot;</span>

      <form method="post" action="order-status.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="reference" value="<?= e($reference) ?>">
        <input type="hidden" name="channel" value="email">
        <button class="linkbtn" type="submit" name="action" value="send-code">
          Email it instead
        </button>
      </form>
    </div>

  <?php else: ?>

    <!-- Step one: the reference. -->
    <p class="reserve__card-note">
      Type the reference from your confirmation — it looks like
      <code>MD-7K3QB2</code> and is in the email we sent you. We will
      text a code to the number on the order to make sure it is you.
    </p>

    <form method="post" action="order-status.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="field">
        <label class="field__label" for="reference">Order reference</label>
        <input class="field__input" type="text" id="reference" name="reference"
               value="<?= e($reference) ?>" placeholder="MD-7K3QB2" required
               autocapitalize="characters" spellcheck="false" autocomplete="off">
      </div>

      <button class="button button--block" type="submit" name="action" value="send-code">
        Text me a code
      </button>
    </form>

  <?php endif; ?>

  </div><!-- /.status-lookup -->
</details>
