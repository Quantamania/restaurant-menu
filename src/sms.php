<?php
/* ===========================================================================
 * SENDING SMS
 *
 * Same shape as mail.php: drivers behind one function, send_sms().
 *
 *   log            written to the outbox, nothing sent. The default.
 *   africastalking really sent, over their HTTP API.
 *
 * Africa's Talking rather than Twilio because the numbers here are Kenyan:
 * delivery to Safaricom and Airtel is direct, it is priced in shillings,
 * and the sandbox is free. Swapping in another provider means adding one
 * more case to the switch — nothing outside this file knows the difference.
 * =========================================================================== */

require_once __DIR__ . '/mail.php';   // for secrets()


/**
 * Puts a Kenyan number into the form the provider expects (+254…).
 *
 * People type their number every way there is: 0712 345 678,
 * 254712345678, +254-712-345678. All of those are the same phone, and the
 * provider only accepts one of the spellings, so we do the converting
 * rather than making the customer do it.
 *
 * Returns null if it cannot be read as a phone number at all, so the
 * caller can say so instead of sending into the void.
 */
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

    // A Kenyan mobile is 254 then nine digits. Anything far from that
    // length is a typo, and sending to it just wastes a message.
    if (strlen($digits) < 11 || strlen($digits) > 15) {
        return null;
    }

    return '+' . $digits;
}


/**
 * Sends one SMS.
 *
 * Returns ['ok' => bool, 'driver' => string, 'error' => ?string,
 *          'ref' => ?string]. Like send_email() it never throws.
 */
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
                // Nothing to do — notify.php has already written the row to
                // the notifications table, which IS the record in this mode.
                return ['ok' => true, 'driver' => 'log', 'ref' => null, 'error' => null];
        }
    } catch (Throwable $ex) {
        return ['ok' => false, 'driver' => $driver, 'ref' => null,
                'error' => trim($ex->getMessage())];
    }
}


/**
 * Hands one message to Africa's Talking.
 *
 * Their reply is a per-recipient list, because one request can carry many
 * numbers. We only ever send one, so we read the first entry — but we check
 * it is there rather than assuming, since an account with no credit returns
 * a perfectly valid response with an empty list.
 */
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

    // An unregistered sender ID is rejected outright, so it is only sent
    // when one has actually been configured.
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
        // This is the "no credit" and "blacklisted number" case. Their
        // top-level Message field says which, so pass it straight through.
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

    // messageId is worth keeping: it is what you quote to the provider when
    // a customer swears the message never arrived.
    return ['ok' => true, 'driver' => 'africastalking',
            'ref' => $first['messageId'] ?? null, 'error' => null];
}
