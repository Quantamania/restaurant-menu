<?php
/* MESSAGING — everything the site sends to a customer */


/* PART 1 — SETTINGS */

/* The mail/sms/payment settings, with the environment overriding the file. */
function secrets(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    // One settings file now; config() in functions.php reads and caches it.
    $conf = config();

    // An environment variable beats the file.
    $env = static function (string $name, $fallback) {
        $value = getenv($name);
        return ($value === false || $value === '') ? $fallback : $value;
    };

    $mail = $conf['mail'] ?? [];
    $smtp = $mail['smtp'] ?? [];
    $sms  = $conf['sms'] ?? [];
    $at   = $sms['africastalking'] ?? [];
    $pay  = $conf['payments'] ?? [];
    $mp   = $pay['mpesa'] ?? [];

    $cache = [
        'base_url' => $env('BASE_URL', $conf['base_url'] ?? ''),
        'staff' => [
            'password_hash' => $env('STAFF_PASSWORD_HASH',
                                    $conf['staff']['password_hash'] ?? ''),
            // Defaults to true when absent.
            'require_password' => !in_array(
                strtolower((string) $env('STAFF_REQUIRE_PASSWORD',
                    ($conf['staff']['require_password'] ?? true) ? 'true' : 'false')),
                ['false', '0', 'no', 'off'],
                true
            ),
        ],
        'mail' => [
            'driver'     => $env('MAIL_DRIVER',   $mail['driver']     ?? 'log'),
            'from_email' => $env('MAIL_FROM',     $mail['from_email'] ?? 'orders@example.com'),
            'from_name'  => $env('MAIL_FROM_NAME',$mail['from_name']  ?? ''),
            'reply_to'   => $env('MAIL_REPLY_TO', $mail['reply_to']   ?? ''),
            'smtp' => [
                'host'       => $env('SMTP_HOST',       $smtp['host']       ?? ''),
                'port'       => (int) $env('SMTP_PORT', $smtp['port']       ?? 587),
                'encryption' => $env('SMTP_ENCRYPTION', $smtp['encryption'] ?? 'tls'),
                'username'   => $env('SMTP_USERNAME',   $smtp['username']   ?? ''),
                'password'   => $env('SMTP_PASSWORD',   $smtp['password']   ?? ''),
                'timeout'    => (int) ($smtp['timeout'] ?? 15),
            ],
        ],
        'sms' => [
            'driver' => $env('SMS_DRIVER', $sms['driver'] ?? 'log'),
            'from'   => $env('SMS_FROM',   $sms['from']   ?? ''),
            'africastalking' => [
                'username' => $env('AT_USERNAME', $at['username'] ?? 'sandbox'),
                'api_key'  => $env('AT_API_KEY',  $at['api_key']  ?? ''),
            ],
        ],
        'payments' => [
            'driver' => $env('PAYMENTS_DRIVER', $pay['driver'] ?? 'manual'),
            'mpesa' => [
                'env'             => $env('MPESA_ENV',             $mp['env']             ?? 'sandbox'),
                'shortcode'       => $env('MPESA_SHORTCODE',       $mp['shortcode']       ?? ''),
                'passkey'         => $env('MPESA_PASSKEY',         $mp['passkey']         ?? ''),
                'consumer_key'    => $env('MPESA_CONSUMER_KEY',    $mp['consumer_key']    ?? ''),
                'consumer_secret' => $env('MPESA_CONSUMER_SECRET', $mp['consumer_secret'] ?? ''),
                'callback_url'    => $env('MPESA_CALLBACK_URL',    $mp['callback_url']    ?? ''),
            ],
        ],
    ];

    return $cache;
}


/* Sends one email. */
function send_email(string $to, string $subject, string $text, ?string $html = null): array
{
    $conf   = secrets()['mail'];
    $driver = $conf['driver'];

    // A header is one line.
    $to      = trim(str_replace(["\r", "\n", "\0"], '', $to));
    $subject = trim(str_replace(["\r", "\n", "\0"], '', $subject));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'driver' => $driver, 'error' => 'Not a valid email address'];
    }

    $message = build_email_message($to, $subject, $text, $html);

    try {
        switch ($driver) {
            case 'smtp':
                smtp_deliver($to, $message, $conf);
                return ['ok' => true, 'driver' => 'smtp', 'error' => null];

            case 'mail':
                // mail() wants the headers separately from the body.
                $ok = mail($to, $message['encoded_subject'], $message['body'],
                           implode("\r\n", $message['headers_without_to']));
                return ['ok' => (bool) $ok, 'driver' => 'mail',
                        'error' => $ok ? null : 'PHP mail() returned false'];

            case 'log':
            default:
                // Written where a human can read it.
                $dir = dirname(__DIR__) . '/storage/mail';
                if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                    return ['ok' => true, 'driver' => 'log',
                            'error' => 'Recorded in notifications; the filesystem is read-only'];
                }
                $name = date('Ymd-His') . '-' . substr(sha1($to . $subject . microtime()), 0, 6) . '.eml';
                $written = @file_put_contents(
                    $dir . '/' . $name,
                    implode("\r\n", $message['headers']) . "\r\n\r\n" . $message['body']
                );
                return ['ok' => $written !== false, 'driver' => 'log',
                        'error' => $written === false ? 'Could not write to storage/mail' : null];
        }
    } catch (Throwable $ex) {
        // The provider's own words are worth keeping — "535 Username and
        return ['ok' => false, 'driver' => $driver, 'error' => trim($ex->getMessage())];
    }
}


/* Turns the pieces into headers and a body. */
function build_email_message(string $to, string $subject, string $text, ?string $html): array
{
    $conf = secrets()['mail'];
    $site = site_name_for_mail();

    // Stripped HERE, not only in send_email().
    $strip     = static fn (string $v): string => str_replace(["\r", "\n", "\0"], '', $v);
    $subject   = $strip($subject);
    $to        = $strip($to);
    $fromEmail = $strip((string) $conf['from_email']);
    $fromName  = $strip($conf['from_name'] !== '' ? $conf['from_name'] : $site);

    // Anything outside ASCII has to be encoded or it arrives as mojibake.
    $encodedSubject = mb_check_encoding($subject, 'ASCII')
        ? $subject
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $encodedName = mb_check_encoding($fromName, 'ASCII')
        ? '"' . str_replace('"', '', $fromName) . '"'
        : '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    // A Message-ID has to be globally unique; host + random is enough.
    $host      = parse_url($conf['smtp']['host'] ?: 'localhost', PHP_URL_HOST) ?: 'localhost';
    $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $host . '>';

    $headers = [
        'Date: ' . date('r'),
        'Message-ID: ' . $messageId,
        'From: ' . $encodedName . ' <' . $fromEmail . '>',
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        // Tells well-behaved senders not to reply to this automatically.
        'Auto-Submitted: auto-generated',
    ];

    if ($conf['reply_to'] !== '') {
        $headers[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $conf['reply_to']);
    }

    if ($html === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = normalise_crlf($text);
    } else {
        $boundary  = 'b' . bin2hex(random_bytes(10));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = "This is a message in several parts.\r\n\r\n"
              . '--' . $boundary . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . normalise_crlf($text) . "\r\n\r\n"
              . '--' . $boundary . "\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . normalise_crlf($html) . "\r\n\r\n"
              . '--' . $boundary . "--\r\n";
    }

    // mail() adds its own To: and Subject:, so it needs the list without them.
    $withoutTo = array_values(array_filter($headers, static fn ($h) =>
        !str_starts_with($h, 'To: ') && !str_starts_with($h, 'Subject: ')));

    return [
        'headers'             => $headers,
        'headers_without_to'  => $withoutTo,
        'encoded_subject'     => $encodedSubject,
        'body'                => $body,
        'from'                => $fromEmail,
    ];
}


/* Every line in a mail message ends CRLF, whatever the source used. */
function normalise_crlf(string $s): string
{
    return preg_replace('/\r\n|\r|\n/', "\r\n", $s);
}


/* The site name, for the From line. Falls back if content.php is absent. */
function site_name_for_mail(): string
{
    static $name = null;

    if ($name === null) {
        $file = __DIR__ . '/content.php';
        $site = is_file($file) ? require $file : [];
        $name = $site['name'] ?? 'Maridadi Eat';
    }

    return $name;
}


/* ------------------------------------------------------------------ the SMTP conversa */

/* Connects, authenticates, sends one message, hangs up. */
function smtp_deliver(string $to, array $message, array $conf): void
{
    $smtp = $conf['smtp'];

    if ($smtp['host'] === '') {
        throw new RuntimeException('No SMTP host configured');
    }

    $encryption = strtolower((string) $smtp['encryption']);
    // Port 465 is encrypted from the first byte, so the socket itself is ssl://.
    $address = ($encryption === 'ssl' ? 'ssl://' : '') . $smtp['host'] . ':' . $smtp['port'];

    $context = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
    ]);

    $socket = @stream_socket_client(
        $address, $errNo, $errStr, max(1, $smtp['timeout']),
        STREAM_CLIENT_CONNECT, $context
    );

    if (!$socket) {
        throw new RuntimeException("Could not reach {$smtp['host']}:{$smtp['port']} — "
            . ($errStr !== '' ? $errStr : "error $errNo"));
    }

    stream_set_timeout($socket, max(1, $smtp['timeout']));

    try {
        smtp_expect($socket, 220);                      // the greeting

        // EHLO must name us.
        $me = gethostname() ?: 'localhost';
        smtp_say($socket, 'EHLO ' . $me);
        smtp_expect($socket, 250);

        if ($encryption === 'tls') {
            smtp_say($socket, 'STARTTLS');
            smtp_expect($socket, 220);

            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed — could not negotiate TLS');
            }

            // The capability list changes once encrypted (AUTH usually only appears now).
            smtp_say($socket, 'EHLO ' . $me);
            smtp_expect($socket, 250);
        }

        if ($smtp['username'] !== '') {
            // AUTH LOGIN: the server prompts twice with 334, and each answer is base64.
            if ($encryption === 'none') {
                throw new RuntimeException('Refusing to send a password over an unencrypted connection');
            }

            smtp_say($socket, 'AUTH LOGIN');
            smtp_expect($socket, 334);
            smtp_say($socket, base64_encode($smtp['username']));
            smtp_expect($socket, 334);
            smtp_say($socket, base64_encode($smtp['password']));
            // 235 = accepted.
            smtp_expect($socket, 235);
        }

        smtp_say($socket, 'MAIL FROM:<' . $message['from'] . '>');
        smtp_expect($socket, 250);

        smtp_say($socket, 'RCPT TO:<' . $to . '>');
        smtp_expect($socket, [250, 251]);

        smtp_say($socket, 'DATA');
        smtp_expect($socket, 354);

        // A lone "." ends the message.
        $payload = implode("\r\n", $message['headers']) . "\r\n\r\n" . $message['body'];
        $payload = preg_replace('/^\./m', '..', $payload);

        smtp_write($socket, $payload . "\r\n.\r\n");
        smtp_expect($socket, 250);

        smtp_say($socket, 'QUIT');

    } finally {
        // Closed whatever happened, so a refused login does not leak a socket.
        @fclose($socket);
    }
}


/* Writes one command, terminated the way SMTP expects. */
function smtp_say($socket, string $command): void
{
    smtp_write($socket, $command . "\r\n");
}


function smtp_write($socket, string $data): void
{
    if (@fwrite($socket, $data) === false) {
        throw new RuntimeException('The connection closed while sending');
    }
}


/* Reads one reply and checks its code. */
function smtp_expect($socket, $wanted): string
{
    $wanted = (array) $wanted;
    $lines  = [];

    while (true) {
        $line = @fgets($socket, 1024);

        if ($line === false || $line === '') {
            $info = stream_get_meta_data($socket);
            throw new RuntimeException(!empty($info['timed_out'])
                ? 'The mail server stopped responding'
                : 'The mail server closed the connection');
        }

        $lines[] = rtrim($line);

        // Continuation lines have a '-' in the fourth character.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    $reply = implode(' ', $lines);
    $code  = (int) substr($reply, 0, 3);

    if (!in_array($code, $wanted, true)) {
        // Carry the server's wording through: it is the useful part.
        throw new RuntimeException($reply);
    }

    return $reply;
}

/* PART 2 — SENDING SMS */

/* Puts a Kenyan number into the form the provider expects (+254…). */
function normalise_phone(string $raw, string $countryCode = '254'): ?string
{
    // A leading + is meaningful; every other non-digit is noise.
    $plus   = str_starts_with(trim($raw), '+');
    $digits = preg_replace('/\D+/', '', $raw);

    if ($digits === '') {
        return null;
    }

    if ($plus || str_starts_with($digits, $countryCode)) {
        // Already international.
        $digits = ltrim($digits, '0');
    } elseif (str_starts_with($digits, '0')) {
        // 0712345678 — the local way of writing it.
        $digits = $countryCode . substr($digits, 1);
    } else {
        // 712345678 — written without the leading zero.
        $digits = $countryCode . $digits;
    }

    // A Kenyan mobile is 254 then nine digits.
    if (strlen($digits) < 11 || strlen($digits) > 15) {
        return null;
    }

    return '+' . $digits;
}


/* Sends one SMS. */
function send_sms(string $to, string $text): array
{
    $conf   = secrets()['sms'];
    $driver = $conf['driver'];

    $number = normalise_phone($to);

    if ($number === null) {
        return ['ok' => false, 'driver' => $driver, 'ref' => null,
                'error' => 'Not a phone number we can send to'];
    }

    try {
        switch ($driver) {
            case 'africastalking':
                return africastalking_send($number, $text, $conf);

            case 'log':
            default:
                // Nothing to do — notify_send() has already written the row to the notifications table.
                return ['ok' => true, 'driver' => 'log', 'ref' => null, 'error' => null];
        }
    } catch (Throwable $ex) {
        return ['ok' => false, 'driver' => $driver, 'ref' => null,
                'error' => trim($ex->getMessage())];
    }
}


/* Hands one message to Africa's Talking. */
function africastalking_send(string $number, string $text, array $conf): array
{
    $at = $conf['africastalking'];

    if (($at['api_key'] ?? '') === '') {
        return ['ok' => false, 'driver' => 'africastalking', 'ref' => null,
                'error' => 'No Africa\'s Talking API key configured'];
    }

    // The sandbox is a different host, not a flag on the same one.
    $host = $at['username'] === 'sandbox'
        ? 'https://api.sandbox.africastalking.com'
        : 'https://api.africastalking.com';

    $fields = [
        'username' => $at['username'],
        'to'       => $number,
        'message'  => $text,
    ];

    // An unregistered sender ID is rejected outright.
    if (($conf['from'] ?? '') !== '') {
        $fields['from'] = $conf['from'];
    }

    $ch = curl_init($host . '/version1/messaging');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'apiKey: ' . $at['api_key'],
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'driver' => 'africastalking', 'ref' => null,
                'error' => 'Could not reach the SMS provider — ' . $cErr];
    }

    $body = json_decode((string) $raw, true);

    if (!is_array($body)) {
        return ['ok' => false, 'driver' => 'africastalking', 'ref' => null,
                'error' => "The SMS provider replied with HTTP $code and no JSON"];
    }

    $recipients = $body['SMSMessageData']['Recipients'] ?? [];

    if (!$recipients) {
        // This is the "no credit" and "blacklisted number" case.
        $why = $body['SMSMessageData']['Message'] ?? "HTTP $code";
        return ['ok' => false, 'driver' => 'africastalking', 'ref' => null,
                'error' => 'The provider accepted nothing: ' . $why];
    }

    $first  = $recipients[0];
    $status = $first['status'] ?? 'Unknown';

    if ($status !== 'Success') {
        return ['ok' => false, 'driver' => 'africastalking',
                'ref' => $first['messageId'] ?? null,
                'error' => $status . ' (' . ($first['statusCode'] ?? '?') . ')'];
    }

    // messageId is worth keeping.
    return ['ok' => true, 'driver' => 'africastalking',
            'ref' => $first['messageId'] ?? null, 'error' => null];
}

/* PART 3 — WHAT GETS SENT, AND WHEN */

/* Where the site lives, for links inside emails. */
function site_base_url(): string
{
    $configured = secrets()['base_url'];

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    // Guessed from the current request.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}


/* The link a customer follows to watch their order. */
function track_url(string $reference): string
{
    return site_base_url() . '/order-status.php?ref=' . urlencode($reference);
}


/* Records a message and sends it. */
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

    // Write it down first.
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
        // The outbox being unavailable must not stop the message.
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

/* "We have your order." Sent the moment it is saved, before payment — because the refe */
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

    /* --- SMS --- */
    $sms = sprintf('%s: order %s received, %s %s. Track: %s',
        $site['name'], $order['reference'], $when, $how, $link);

    notify_send('sms', 'order_placed', $order['phone'], null, $sms, (int) $order['id']);
}


/* "Your money arrived." Only sent once a payment actually succeeds. */
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


/* Tells the customer the kitchen has moved their order on. */
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

/* Makes a six-digit code, stores its hash, and sends it. */
function issue_access_code(array $order, string $channel): array
{
    $pdo = getPDO();

    // A cap on how many can be asked for.
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

    // random_int, not rand.
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Any code sent earlier stops working.
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


/* Checks a typed code against the live one for an order. */
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

    // Counted before the comparison.
    $pdo->prepare('UPDATE order_access_codes SET attempts = attempts + 1 WHERE id = ?')
        ->execute([$row['id']]);

    if ((int) $row['attempts'] >= 5) {
        $pdo->prepare('UPDATE order_access_codes SET expires_at = NOW() WHERE id = ?')
            ->execute([$row['id']]);
        return ['ok' => false, 'error' =>
            'Too many wrong tries. That code is now dead — ask for a new one.'];
    }

    // hash_equals, not ===, so the time taken does not leak how much of the code was right.
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

/* content.php, loaded once. */
function order_site(): array
{
    static $site = null;

    if ($site === null) {
        $site = require __DIR__ . '/content.php';
    }

    return $site;
}


/* "Thu 5 Nov, 1:30 pm" — the slot label if we have one, else the raw time. */
function order_when_words(array $order, array $site): string
{
    $label = $site['order_times'][$order['order_time']]
        ?? date('g:i a', strtotime($order['order_time']));

    return date('D j M', strtotime($order['order_date'])) . ', ' . $label;
}


/* Appends the money summary to a plain-text email. */
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


/* The HTML half of an email. */
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
