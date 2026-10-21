<?php
/* ===========================================================================
 * SENDING EMAIL
 *
 * Three drivers behind one function, send_email():
 *
 *   log   the message is written to storage/mail/ and nothing is sent.
 *         The default. Development works with no account and no internet.
 *   smtp  the message is really sent, by talking SMTP to a mail server.
 *   mail  handed to PHP's mail(), which needs a local MTA on the machine.
 *
 * Which one runs comes from src/secrets.php (or the environment). Callers
 * never know or care which it was — they get back the same array either way.
 *
 * Why a hand-written SMTP client instead of a library: the site has no
 * composer, no vendor/ and no build step, which is deliberate — it has to
 * stay something you can copy onto cheap shared hosting and run. An SMTP
 * conversation is about eighty lines, so that is what this is.
 * =========================================================================== */


/**
 * The mail/sms/payment settings, with the environment overriding the file.
 *
 * Read once and remembered: this is called several times per request and
 * there is no reason to touch the disk again.
 */
function secrets(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $file = __DIR__ . '/secrets.php';
    $conf = is_file($file) ? require $file : [];

    // An environment variable beats the file, so a server can hold its
    // passwords outside the document root without editing any code.
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
            // Defaults to true when absent: a config file written before this
            // setting existed must not silently open the kitchen screen.
            // Anything other than an explicit "off" word counts as on, so a
            // typo fails safe rather than unlocking it.
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


/**
 * Sends one email.
 *
 * Returns ['ok' => bool, 'driver' => string, 'error' => ?string]. It never
 * throws: a confirmation email failing to send must not lose a customer's
 * order, so the caller gets told and decides what to do.
 */
function send_email(string $to, string $subject, string $text, ?string $html = null): array
{
    $conf   = secrets()['mail'];
    $driver = $conf['driver'];

    // A header is one line, so a newline smuggled into the recipient or the
    // subject would let somebody append headers of their own — a Bcc, say.
    // Both are cleaned before they go anywhere near the message.
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
                // Written where a human can read it. This is what makes the
                // flow testable with no account anywhere.
                $dir = dirname(__DIR__) . '/storage/mail';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
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
        // Password not accepted" tells you exactly what to fix, where
        // "could not send email" tells you nothing.
        return ['ok' => false, 'driver' => $driver, 'error' => trim($ex->getMessage())];
    }
}


/**
 * Turns the pieces into headers and a body.
 *
 * If an HTML version is supplied the message is multipart/alternative: a
 * mail reader that wants HTML shows that part, and one that does not —
 * or a person who has turned HTML off — reads the plain text. The plain
 * part is written to be properly readable, not a sad fallback.
 */
function build_email_message(string $to, string $subject, string $text, ?string $html): array
{
    $conf = secrets()['mail'];
    $site = site_name_for_mail();

    // Stripped HERE, not only in send_email(). A header is one line, so a
    // newline in any of these values lets the rest of the string become
    // headers of its own — "Subject: Hi\r\nBcc: someone-else" quietly adds
    // a recipient. This is the function that builds the headers, so this is
    // where that has to be impossible; a caller that forgets is then not a
    // security hole.
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
        // Tells well-behaved senders not to reply to this automatically,
        // and keeps an out-of-office from bouncing back at the kitchen.
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


/** Every line in a mail message ends CRLF, whatever the source used. */
function normalise_crlf(string $s): string
{
    return preg_replace('/\r\n|\r|\n/', "\r\n", $s);
}


/** The site name, for the From line. Falls back if content.php is absent. */
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


/* ------------------------------------------------------------------ the SMTP
 * conversation.
 *
 * Plain sockets. Each step is "say something, expect a numeric code", so
 * one small helper does the reading and the whole exchange reads like the
 * transcript it is.
 * ------------------------------------------------------------------------- */

/**
 * Connects, authenticates, sends one message, hangs up.
 * Throws RuntimeException with the server's own reply on any refusal.
 */
function smtp_deliver(string $to, array $message, array $conf): void
{
    $smtp = $conf['smtp'];

    if ($smtp['host'] === '') {
        throw new RuntimeException('No SMTP host configured');
    }

    $encryption = strtolower((string) $smtp['encryption']);
    // Port 465 is encrypted from the first byte, so the socket itself is
    // ssl://. Port 587 starts in the clear and is upgraded with STARTTLS.
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

        // EHLO must name us. The hostname is cosmetic to most servers but
        // some reject an obviously bogus one.
        $me = gethostname() ?: 'localhost';
        smtp_say($socket, 'EHLO ' . $me);
        smtp_expect($socket, 250);

        if ($encryption === 'tls') {
            smtp_say($socket, 'STARTTLS');
            smtp_expect($socket, 220);

            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed — could not negotiate TLS');
            }

            // The capability list changes once encrypted (AUTH usually only
            // appears now), so the greeting is repeated inside the tunnel.
            smtp_say($socket, 'EHLO ' . $me);
            smtp_expect($socket, 250);
        }

        if ($smtp['username'] !== '') {
            // AUTH LOGIN: the server prompts twice with 334, and each answer
            // is base64. Only ever do this on an encrypted connection —
            // base64 is not encryption, it is just an encoding.
            if ($encryption === 'none') {
                throw new RuntimeException('Refusing to send a password over an unencrypted connection');
            }

            smtp_say($socket, 'AUTH LOGIN');
            smtp_expect($socket, 334);
            smtp_say($socket, base64_encode($smtp['username']));
            smtp_expect($socket, 334);
            smtp_say($socket, base64_encode($smtp['password']));
            // 235 = accepted. Gmail answers 535 here when the password is a
            // normal account password instead of an App Password.
            smtp_expect($socket, 235);
        }

        smtp_say($socket, 'MAIL FROM:<' . $message['from'] . '>');
        smtp_expect($socket, 250);

        smtp_say($socket, 'RCPT TO:<' . $to . '>');
        smtp_expect($socket, [250, 251]);

        smtp_say($socket, 'DATA');
        smtp_expect($socket, 354);

        // A lone "." ends the message, so any line that really starts with a
        // dot gets a second one added. The server strips it back off.
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


/** Writes one command, terminated the way SMTP expects. */
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


/**
 * Reads one reply and checks its code.
 *
 * A reply can run over several lines: every line but the last has a dash
 * after the number ("250-STARTTLS"), the last has a space ("250 HELP").
 */
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
