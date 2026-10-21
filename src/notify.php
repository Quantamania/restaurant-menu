<?php
/* ===========================================================================
 * WHAT THE SITE SENDS, AND WHEN
 *
 * mail.php and sms.php know HOW to send. This file knows WHAT to send and
 * writes down that it happened.
 *
 * Every message goes through notify_send(), which:
 *   1. writes a 'queued' row in notifications — before trying, so a crash
 *      mid-send still leaves evidence
 *   2. hands it to the driver
 *   3. marks the row 'sent' or 'failed', keeping the provider's own words
 *
 * Nothing in here throws. A customer's order is already saved by the time
 * we try to tell them about it, and an email failing is not a reason to
 * lose their lunch. Failures end up in the outbox for somebody to look at.
 * =========================================================================== */

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/sms.php';


/** Where the site lives, for links inside emails. */
function site_base_url(): string
{
    $configured = secrets()['base_url'];

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    // Guessed from the current request. Right for a person clicking around,
    // wrong for anything sent from the command line — which is exactly why
    // BASE_URL exists.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}


/** The link a customer follows to watch their order. */
function track_url(string $reference): string
{
    return site_base_url() . '/order-status.php?ref=' . urlencode($reference);
}


/**
 * Records a message and sends it.
 *
 * $channel  'email' or 'sms'
 * $purpose  why it went out — order_placed, payment_receipt, access_code
 */
function notify_send(
    string $channel,
    string $purpose,
    string $recipient,
    ?string $subject,
    string $body,
    ?int $orderId = null,
    ?string $html = null
): array {
    $driver = $channel === 'sms'
        ? secrets()['sms']['driver']
        : secrets()['mail']['driver'];

    $rowId = null;

    // Write it down first. If the send hangs and the request is killed, the
    // queued row is what tells you it was attempted.
    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO notifications
               (order_id, channel, purpose, recipient, subject, body, status, driver)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $channel, $purpose, $recipient, $subject, $body, 'queued', $driver]);
        $rowId = (int) $pdo->lastInsertId();
    } catch (Throwable $ex) {
        // The outbox being unavailable must not stop the message. Log and
        // carry on sending blind.
        error_log('notifications insert failed: ' . $ex->getMessage());
    }

    $result = $channel === 'sms'
        ? send_sms($recipient, $body)
        : send_email($recipient, (string) $subject, $body, $html);

    if ($rowId !== null) {
        try {
            getPDO()->prepare(
                'UPDATE notifications SET status = ?, error = ?, provider_ref = ? WHERE id = ?'
            )->execute([
                $result['ok'] ? 'sent' : 'failed',
                $result['error'] !== null ? mb_substr($result['error'], 0, 255) : null,
                $result['ref'] ?? null,
                $rowId,
            ]);
        } catch (Throwable $ex) {
            error_log('notifications update failed: ' . $ex->getMessage());
        }
    }

    if (!$result['ok']) {
        error_log(sprintf('%s to %s failed (%s): %s',
            $channel, $recipient, $result['driver'], (string) $result['error']));
    }

    return $result;
}


/* ------------------------------------------------------- the messages ----- */

/**
 * "We have your order." Sent the moment it is saved, before payment —
 * because the reference is what the customer needs if anything goes wrong
 * on the payment screen, and they must have it even if they close the tab.
 */
function notify_order_placed(array $order): void
{
    $site  = order_site();
    $first = explode(' ', trim($order['name']))[0];
    $when  = order_when_words($order, $site);
    $link  = track_url($order['reference']);
    $how   = $order['order_type'] === 'delivery' ? 'delivered to you' : 'ready to collect';

    /* --- email --- */
    $lines = [];
    foreach ($order['items'] as $item) {
        $lines[] = sprintf('  %d x %-28s KSh %s',
            (int) $item['quantity'], $item['name'],
            number_format($item['quantity'] * $item['unit_price'], 0));
    }

    // The marker is @@ITEMS@@ rather than a variable: a heredoc interpolates
    // {$name} as it is built, so a placeholder written that way would be
    // substituted (to nothing) before str_replace ever saw it.
    $text = <<<TEXT
Hi {$first},

We have your order. Your reference is {$order['reference']}.

WHAT YOU ORDERED
@@ITEMS@@

WHEN
  {$when}, {$how}

TRACK IT
  {$link}

Quote {$order['reference']} if you call us on {$site['phone']}.

{$site['name']}
TEXT;

    // The heredoc above cannot hold a loop, so the item block is spliced in.
    $text = str_replace('@@ITEMS@@', implode("\n", $lines), $text);
    $text = order_money_block($text, $order);

    notify_send(
        'email', 'order_placed', $order['email'],
        'Order ' . $order['reference'] . ' — we have it',
        $text, (int) $order['id'],
        order_email_html($order, $site, 'We have your order', $when, $how)
    );

    /* --- SMS ---
       160 characters is one message; past that the customer is charged for
       two. The reference and the link are the whole point, so everything
       else is cut to fit. */
    $sms = sprintf('%s: order %s received, %s %s. Track: %s',
        $site['name'], $order['reference'], $when, $how, $link);

    notify_send('sms', 'order_placed', $order['phone'], null, $sms, (int) $order['id']);
}


/** "Your money arrived." Only sent once a payment actually succeeds. */
function notify_payment_receipt(array $order, array $payment): void
{
    $site  = order_site();
    $first = explode(' ', trim($order['name']))[0];
    $total = number_format((float) $order['total'], 0);
    $link  = track_url($order['reference']);
    $ref   = $payment['provider_ref'] ?? $order['reference'];

    $text = <<<TEXT
Hi {$first},

We have received KSh {$total} for order {$order['reference']}.

  Paid by     M-Pesa
  Receipt     {$ref}

Nothing more to do — we will have your food ready.

Track it: {$link}

{$site['name']}
TEXT;

    notify_send(
        'email', 'payment_receipt', $order['email'],
        'Payment received — order ' . $order['reference'],
        $text, (int) $order['id'],
        order_email_html($order, $site, 'Payment received', null, null,
            "We have received <strong>KSh {$total}</strong>. Receipt " . e($ref) . '.')
    );

    $sms = sprintf('%s: KSh %s received for order %s. Receipt %s. Thank you.',
        $site['name'], $total, $order['reference'], $ref);

    notify_send('sms', 'payment_receipt', $order['phone'], null, $sms, (int) $order['id']);
}


/**
 * Tells the customer the kitchen has moved their order on.
 *
 * NOT sent for every stage. "We have started cooking" is of no use to
 * anybody and every SMS costs the restaurant money, so only the two moments
 * that change what the customer should DO are sent:
 *
 *   ready      go and get it, or expect the door
 *   cancelled  stop expecting food
 *
 * 'preparing' and 'completed' are visible on the tracking page and are
 * deliberately silent.
 */
function notify_order_status(array $order, string $status): void
{
    if (!in_array($status, ['ready', 'cancelled'], true)) {
        return;
    }

    $site     = order_site();
    $first    = explode(' ', trim($order['name']))[0];
    $link     = track_url($order['reference']);
    $delivery = $order['order_type'] === 'delivery';

    if ($status === 'ready') {
        $subject = $delivery
            ? 'Order ' . $order['reference'] . ' is on its way'
            : 'Order ' . $order['reference'] . ' is ready';

        $heading = $delivery ? 'Your food is on its way' : 'Your food is ready';

        $what = $delivery
            ? "The rider has your order. Keep your phone nearby — we will call\n"
              . "{$order['phone']} if we cannot find you."
            : "Come to the counter whenever you are ready and quote\n"
              . "{$order['reference']}.";

        $owing = $order['payment_status'] !== 'paid'
            ? sprintf("\nThere is KSh %s to pay when you %s.\n",
                      number_format((float) $order['total'], 0),
                      $delivery ? 'take delivery' : 'collect')
            : '';

        $text = <<<TEXT
Hi {$first},

{$heading}.

{$what}
{$owing}
Track it: {$link}

{$site['name']}
TEXT;

        $sms = $delivery
            ? sprintf('%s: order %s is on its way. Keep your phone nearby.',
                      $site['name'], $order['reference'])
            : sprintf('%s: order %s is ready to collect. Quote %s at the counter.',
                      $site['name'], $order['reference'], $order['reference']);

    } else {
        $subject = 'Order ' . $order['reference'] . ' was cancelled';
        $heading = 'Your order was cancelled';

        $text = <<<TEXT
Hi {$first},

Order {$order['reference']} has been cancelled, so no food is coming.

You have not been charged. If this is wrong, call us on {$site['phone']}
and we will sort it out.

{$site['name']}
TEXT;

        $sms = sprintf('%s: order %s was cancelled and you have not been charged. Call %s if that is wrong.',
                       $site['name'], $order['reference'], $site['phone']);
    }

    notify_send('email', 'status_' . $status, $order['email'], $subject,
                $text, (int) $order['id'],
                order_email_html($order, $site, $heading));

    notify_send('sms', 'status_' . $status, $order['phone'], null, $sms, (int) $order['id']);
}


/* -------------------------------------------------- one-time access codes -- */

/**
 * Makes a six-digit code, stores its hash, and sends it.
 *
 * $channel is 'sms' or 'email'. Returns ['ok' => bool, 'error' => ?string].
 *
 * Only the hash is kept. If this database is ever copied by somebody who
 * should not have it, they get a list of useless hashes rather than a set
 * of live codes.
 */
function issue_access_code(array $order, string $channel): array
{
    $pdo = getPDO();

    // A cap on how many can be asked for, or the form becomes a free way to
    // send somebody a hundred text messages.
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM order_access_codes
          WHERE order_id = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $recent->execute([$order['id']]);

    if ((int) $recent->fetchColumn() >= 4) {
        return ['ok' => false, 'error' =>
            'We have sent several codes already. Please wait a few minutes, or call us.'];
    }

    $to = $channel === 'sms' ? $order['phone'] : $order['email'];

    if (trim((string) $to) === '') {
        return ['ok' => false, 'error' => 'There is no ' . $channel . ' on that order.'];
    }

    // random_int, not rand: this is a credential, so it has to come from a
    // generator that cannot be predicted from previous values.
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Any code sent earlier stops working, so a customer reading down a list
    // of texts cannot be confused about which one is live.
    $pdo->prepare(
        'UPDATE order_access_codes SET expires_at = NOW()
          WHERE order_id = ? AND used_at IS NULL AND expires_at > NOW()'
    )->execute([$order['id']]);

    $pdo->prepare(
        'INSERT INTO order_access_codes
           (order_id, code_hash, channel, sent_to, expires_at)
         VALUES (?, ?, ?, ?, (NOW() + INTERVAL 10 MINUTE))'
    )->execute([$order['id'], hash('sha256', $code), $channel, $to]);

    $site = order_site();
    $body = sprintf(
        '%s: %s is your code to see order %s. It expires in 10 minutes. We will never ask you for it on the phone.',
        $site['name'], $code, $order['reference']
    );

    if ($channel === 'sms') {
        $result = notify_send('sms', 'access_code', $to, null, $body, (int) $order['id']);
    } else {
        $text = <<<TEXT
Your code is {$code}

Type it on the tracking page to see order {$order['reference']}.
It stops working in 10 minutes.

If you did not ask for this code, somebody typed your order reference by
mistake. You can ignore this email — without the code they cannot see
anything.

{$site['name']}
TEXT;
        $result = notify_send('email', 'access_code', $to,
            'Your code: ' . $code, $text, (int) $order['id']);
    }

    if (!$result['ok']) {
        return ['ok' => false, 'error' =>
            'We could not send the code just now. Please try the other way, or call us.'];
    }

    return ['ok' => true, 'error' => null];
}


/**
 * Checks a typed code against the live one for an order.
 *
 * Returns ['ok' => bool, 'error' => ?string].
 */
function check_access_code(array $order, string $typed): array
{
    $typed = preg_replace('/\D+/', '', $typed);

    if (strlen($typed) !== 6) {
        return ['ok' => false, 'error' => 'The code is six digits.'];
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT * FROM order_access_codes
          WHERE order_id = ? AND used_at IS NULL AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$order['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' =>
            'That code has expired. Ask for a new one.'];
    }

    // Counted before the comparison, so a wrong guess costs an attempt even
    // if the request is abandoned straight afterwards.
    $pdo->prepare('UPDATE order_access_codes SET attempts = attempts + 1 WHERE id = ?')
        ->execute([$row['id']]);

    if ((int) $row['attempts'] >= 5) {
        $pdo->prepare('UPDATE order_access_codes SET expires_at = NOW() WHERE id = ?')
            ->execute([$row['id']]);
        return ['ok' => false, 'error' =>
            'Too many wrong tries. That code is now dead — ask for a new one.'];
    }

    // hash_equals, not ===, so the time taken does not leak how much of the
    // code was right.
    if (!hash_equals($row['code_hash'], hash('sha256', $typed))) {
        $left = 4 - (int) $row['attempts'];
        return ['ok' => false, 'error' => $left > 0
            ? "That code is not right. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'
            : 'That code is not right.'];
    }

    // Spent. A code is good once.
    $pdo->prepare('UPDATE order_access_codes SET used_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);

    return ['ok' => true, 'error' => null];
}


/* ------------------------------------------------------------- small helpers */

/** content.php, loaded once. */
function order_site(): array
{
    static $site = null;

    if ($site === null) {
        $site = require __DIR__ . '/content.php';
    }

    return $site;
}


/** "Thu 5 Nov, 1:30 pm" — the slot label if we have one, else the raw time. */
function order_when_words(array $order, array $site): string
{
    $label = $site['order_times'][$order['order_time']]
        ?? date('g:i a', strtotime($order['order_time']));

    return date('D j M', strtotime($order['order_date'])) . ', ' . $label;
}


/** Appends the money summary to a plain-text email. */
function order_money_block(string $text, array $order): string
{
    $block = "\nFood           KSh " . number_format((float) $order['subtotal'], 0);

    if ((float) $order['delivery_fee'] > 0) {
        $block .= "\nDelivery       KSh " . number_format((float) $order['delivery_fee'], 0);
    }

    $block .= "\nTOTAL          KSh " . number_format((float) $order['total'], 0) . "\n";

    // Dropped in after the item list, before the WHEN heading.
    return str_replace("\nWHEN\n", $block . "\nWHEN\n", $text);
}


/**
 * The HTML half of an email.
 *
 * Deliberately plain: tables and inline styles, no flexbox, no grid, no
 * external stylesheet. Mail readers are a decade behind browsers and
 * several of them strip <style> blocks outright, so anything clever here
 * arrives as a mess. The one thing worth carrying over is the brand colour.
 */
function order_email_html(
    array $order,
    array $site,
    string $heading,
    ?string $when = null,
    ?string $how = null,
    ?string $lead = null
): string {
    $brown = '#1c140e';
    $teal  = '#0f7a74';
    $first = e(explode(' ', trim($order['name']))[0]);
    $link  = e(track_url($order['reference']));
    $ref   = e($order['reference']);

    $rows = '';
    foreach ($order['items'] as $item) {
        $rows .= '<tr>'
              . '<td style="padding:6px 0;color:#55483c;">'
              . (int) $item['quantity'] . ' &times; ' . e($item['name']) . '</td>'
              . '<td style="padding:6px 0;text-align:right;color:#1a1410;white-space:nowrap;">KSh '
              . number_format($item['quantity'] * $item['unit_price'], 0) . '</td>'
              . '</tr>';
    }

    $money = '<tr><td style="padding:4px 0;color:#55483c;">Food</td>'
           . '<td style="padding:4px 0;text-align:right;">KSh '
           . number_format((float) $order['subtotal'], 0) . '</td></tr>';

    if ((float) $order['delivery_fee'] > 0) {
        $money .= '<tr><td style="padding:4px 0;color:#55483c;">Delivery</td>'
                . '<td style="padding:4px 0;text-align:right;">KSh '
                . number_format((float) $order['delivery_fee'], 0) . '</td></tr>';
    }

    $money .= '<tr><td style="padding:8px 0 0;font-weight:bold;border-top:1px solid #e6dfd4;">Total</td>'
            . '<td style="padding:8px 0 0;text-align:right;font-weight:bold;border-top:1px solid #e6dfd4;">KSh '
            . number_format((float) $order['total'], 0) . '</td></tr>';

    $whenBlock = $when !== null
        ? '<p style="margin:0 0 18px;color:#55483c;">' . e($when) . ', ' . e((string) $how) . '.</p>'
        : '';

    $leadBlock = $lead !== null
        ? '<p style="margin:0 0 18px;color:#55483c;">' . $lead . '</p>'
        : '';

    return <<<HTML
<body style="margin:0;padding:0;background:#f6f2ec;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f2ec;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;">

    <tr><td style="background:{$brown};padding:20px 24px;">
      <span style="color:#ffffff;font-size:18px;font-weight:bold;letter-spacing:.04em;">{$site['name']}</span>
    </td></tr>

    <tr><td style="padding:24px;">
      <h1 style="margin:0 0 6px;font-size:21px;color:#1a1410;">{$heading}</h1>
      <p style="margin:0 0 18px;color:#55483c;">Hi {$first}, your reference is
        <strong style="color:{$brown};">{$ref}</strong>.</p>

      {$leadBlock}
      {$whenBlock}

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px;">
        {$rows}
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;font-size:15px;">
        {$money}
      </table>

      <p style="margin:24px 0 0;">
        <a href="{$link}" style="display:inline-block;background:{$teal};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold;">Track this order</a>
      </p>
    </td></tr>

    <tr><td style="padding:18px 24px;background:#f6f2ec;color:#55483c;font-size:13px;">
      Quote <strong>{$ref}</strong> if you call us on {$site['phone']}.<br>
      {$site['address']}
    </td></tr>

  </table>
</td></tr>
</table>
</body>
HTML;
}
