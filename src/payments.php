<?php
/* ===========================================================================
 * TAKING PAYMENT
 *
 * Two ways to pay:
 *
 *   cash   pay the driver, or pay at the counter. Nothing to charge now, so
 *          the order is simply marked as owing money and the kitchen gets
 *          on with it.
 *   mpesa  an STK push: the customer's phone rings with a PIN prompt.
 *
 * And two drivers behind M-Pesa:
 *
 *   manual  no gateway. The payment is recorded as pending and somebody at
 *           the till confirms it against the message on their own phone.
 *           This is what runs with no credentials — and it is also what a
 *           lot of small Kenyan restaurants genuinely do.
 *   mpesa   Safaricom Daraja. Needs credentials and a public callback URL.
 *
 * WHY NO CARD FORM
 *
 * There is deliberately no "type your card number" screen. Accepting a card
 * number on our own server drags the whole site into PCI scope and means
 * storing, logging and accidentally leaking the most sensitive field a
 * customer has. The right way to add cards is a redirect to the gateway's
 * own hosted page (Flutterwave, Paystack, Stripe Checkout), so the number
 * is typed on their domain and never touches ours. That slots in beside
 * 'mpesa' as another method; it is not a form on this site.
 * =========================================================================== */

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/notify.php';


/** Is a real gateway configured, or are we confirming by hand? */
function payments_driver(): string
{
    $driver = secrets()['payments']['driver'];
    $mpesa  = secrets()['payments']['mpesa'];

    // Claiming the 'mpesa' driver without credentials would fail on every
    // order. Fall back rather than break the checkout.
    if ($driver === 'mpesa' && ($mpesa['consumer_key'] === '' || $mpesa['shortcode'] === '')) {
        return 'manual';
    }

    return $driver;
}


/**
 * Starts a payment for an order.
 *
 * $method 'cash' or 'mpesa'. $phone only matters for M-Pesa — it is the
 * number the push goes to, which can be somebody else's.
 *
 * Returns ['ok' => bool, 'status' => string, 'error' => ?string,
 *          'payment_id' => ?int].
 *
 * 'status' is where the PAYMENT got to: 'paid', 'pending' or 'failed'. The
 * caller decides what to show from that — it does not need to know which
 * driver ran.
 */
function start_payment(array $order, string $method, string $phone = ''): array
{
    $pdo    = getPDO();
    $amount = (float) $order['total'];

    if (!in_array($method, ['cash', 'mpesa'], true)) {
        return ['ok' => false, 'status' => 'failed', 'payment_id' => null,
                'error' => 'We do not take payment that way.'];
    }

    // Already settled. Saying so is better than quietly charging twice.
    if ($order['payment_status'] === 'paid') {
        return ['ok' => true, 'status' => 'paid', 'payment_id' => null, 'error' => null];
    }

    /* ---------------------------------------------------------------- cash */
    if ($method === 'cash') {
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'INSERT INTO payments (order_id, method, amount, status, provider_note)
                 VALUES (?, "cash", ?, "pending", "To be paid on handover")'
            )->execute([$order['id'], $amount]);

            $paymentId = (int) $pdo->lastInsertId();

            // 'unpaid' is the honest state: the food is coming, the money
            // has not arrived. The till clears it when the cash is taken.
            $pdo->prepare(
                'UPDATE orders SET payment_method = "cash", payment_status = "unpaid" WHERE id = ?'
            )->execute([$order['id']]);

            $pdo->commit();

            return ['ok' => true, 'status' => 'unpaid', 'payment_id' => $paymentId, 'error' => null];

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('cash payment failed: ' . $ex->getMessage());
            return ['ok' => false, 'status' => 'failed', 'payment_id' => null,
                    'error' => 'We could not record that. Please try again.'];
        }
    }

    /* --------------------------------------------------------------- M-Pesa */
    $number = normalise_phone($phone !== '' ? $phone : $order['phone']);

    if ($number === null) {
        return ['ok' => false, 'status' => 'failed', 'payment_id' => null,
                'error' => 'That does not look like an M-Pesa number.'];
    }

    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'INSERT INTO payments (order_id, method, amount, status, phone)
             VALUES (?, "mpesa", ?, "pending", ?)'
        )->execute([$order['id'], $amount, $number]);

        $paymentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'UPDATE orders SET payment_method = "mpesa", payment_status = "pending" WHERE id = ?'
        )->execute([$order['id']]);

        $pdo->commit();

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('mpesa payment insert failed: ' . $ex->getMessage());
        return ['ok' => false, 'status' => 'failed', 'payment_id' => null,
                'error' => 'We could not start that payment. Please try again.'];
    }

    // The row exists and the order says 'pending' before we talk to anyone.
    // If the push request dies halfway, the pending row is what tells us to
    // go and look rather than silently losing the attempt.
    if (payments_driver() === 'mpesa') {
        $push = mpesa_stk_push($order, $number, $amount, $paymentId);

        if (!$push['ok']) {
            mark_payment_failed($paymentId, $push['error']);
            return ['ok' => false, 'status' => 'failed', 'payment_id' => $paymentId,
                    'error' => $push['error']];
        }

        // Safaricom's answer only means "the prompt was sent". Whether the
        // customer typed their PIN arrives later, on the callback.
        getPDO()->prepare('UPDATE payments SET provider_ref = ? WHERE id = ?')
                ->execute([$push['checkout_id'], $paymentId]);
    }

    return ['ok' => true, 'status' => 'pending', 'payment_id' => $paymentId, 'error' => null];
}


/**
 * Marks a payment as settled, and the order with it.
 *
 * Called from two places: the gateway callback, and the till confirming a
 * payment by hand. Both go through here so the two columns can never
 * disagree — and so the receipt is sent exactly once.
 */
function settle_payment(int $paymentId, ?string $providerRef = null, ?string $note = null): bool
{
    $pdo = getPDO();

    $pdo->beginTransaction();

    try {
        // Locked for the length of the transaction. A provider that fires
        // its callback twice at the same moment would otherwise have both
        // copies read 'pending' and both send a receipt.
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            $pdo->rollBack();
            return false;
        }

        // Already done. Not an error — just nothing left to do.
        if ($payment['status'] === 'paid') {
            $pdo->rollBack();
            return true;
        }

        $pdo->prepare(
            'UPDATE payments SET status = "paid", provider_ref = COALESCE(?, provider_ref),
                    provider_note = COALESCE(?, provider_note) WHERE id = ?'
        )->execute([$providerRef, $note, $paymentId]);

        $pdo->prepare('UPDATE orders SET payment_status = "paid" WHERE id = ?')
            ->execute([$payment['order_id']]);

        $pdo->commit();

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('settle_payment failed: ' . $ex->getMessage());
        return false;
    }

    // Outside the transaction: sending an email is slow and can fail, and
    // neither should hold a database lock open or undo a real payment.
    try {
        $stmt = $pdo->prepare('SELECT reference FROM orders WHERE id = ?');
        $stmt->execute([$payment['order_id']]);
        $reference = (string) $stmt->fetchColumn();

        $order = getOrderByReference($reference);

        if ($order) {
            $fresh = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
            $fresh->execute([$paymentId]);
            notify_payment_receipt($order, $fresh->fetch() ?: []);
        }
    } catch (Throwable $ex) {
        error_log('receipt send failed: ' . $ex->getMessage());
    }

    return true;
}


/** Records that a payment attempt did not work, and why. */
function mark_payment_failed(int $paymentId, ?string $why = null): void
{
    try {
        $pdo = getPDO();

        $stmt = $pdo->prepare('SELECT order_id FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $orderId = $stmt->fetchColumn();

        $pdo->prepare('UPDATE payments SET status = "failed", provider_note = ? WHERE id = ?')
            ->execute([$why !== null ? mb_substr($why, 0, 255) : null, $paymentId]);

        if ($orderId) {
            // Back to 'failed', not 'unpaid': the customer should see that
            // something went wrong rather than find a silently empty screen.
            $pdo->prepare('UPDATE orders SET payment_status = "failed" WHERE id = ?')
                ->execute([$orderId]);
        }
    } catch (Throwable $ex) {
        error_log('mark_payment_failed: ' . $ex->getMessage());
    }
}


/** The newest payment attempt on an order, or null. */
function latest_payment(int $orderId): ?array
{
    $stmt = getPDO()->prepare(
        'SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$orderId]);

    return $stmt->fetch() ?: null;
}


/* ------------------------------------------------------------ Daraja ------
 * Safaricom's STK push. Only reached when the 'mpesa' driver is configured;
 * with the default 'manual' driver none of this runs.
 * ------------------------------------------------------------------------- */

/** An OAuth token, good for an hour. */
function mpesa_token(array $conf): ?string
{
    $base = $conf['env'] === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';

    $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => $conf['consumer_key'] . ':' . $conf['consumer_secret'],
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) {
        return null;
    }

    $body = json_decode((string) $raw, true);

    return is_array($body) ? ($body['access_token'] ?? null) : null;
}


/**
 * Asks Safaricom to put a PIN prompt on the customer's phone.
 *
 * Returns ['ok' => bool, 'checkout_id' => ?string, 'error' => ?string].
 * A true here means the prompt was SENT, not that it was paid.
 */
function mpesa_stk_push(array $order, string $number, float $amount, int $paymentId): array
{
    $conf  = secrets()['payments']['mpesa'];
    $token = mpesa_token($conf);

    if ($token === null) {
        return ['ok' => false, 'checkout_id' => null,
                'error' => 'Could not sign in to M-Pesa. Please try again or pay on collection.'];
    }

    $base = $conf['env'] === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';

    // Their timestamp must be in Nairobi time, and the password is
    // shortcode + passkey + that same timestamp, base64'd.
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
    $password  = base64_encode($conf['shortcode'] . $conf['passkey'] . $timestamp);

    $payload = [
        'BusinessShortCode' => $conf['shortcode'],
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        // Daraja rejects decimals. Shillings only — which is what the menu
        // is priced in anyway.
        'Amount'            => (int) round($amount),
        // Their field wants the number without the +.
        'PartyA'            => ltrim($number, '+'),
        'PartyB'            => $conf['shortcode'],
        'PhoneNumber'       => ltrim($number, '+'),
        // Our payment id rides along so the callback can find this row
        // without having to match on amount and phone.
        'CallBackURL'       => rtrim($conf['callback_url'], '/') . '?p=' . $paymentId,
        'AccountReference'  => mb_substr($order['reference'], 0, 12),
        'TransactionDesc'   => 'Order ' . $order['reference'],
    ];

    $ch = curl_init($base . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);

    $raw  = curl_exec($ch);
    $cErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'checkout_id' => null,
                'error' => 'Could not reach M-Pesa — ' . $cErr];
    }

    $body = json_decode((string) $raw, true);

    if (!is_array($body)) {
        return ['ok' => false, 'checkout_id' => null, 'error' => 'M-Pesa sent back something unreadable'];
    }

    // '0' is the only success. Anything else carries their own wording,
    // which is more useful to a customer than a generic failure.
    if (($body['ResponseCode'] ?? null) !== '0') {
        return ['ok' => false, 'checkout_id' => null,
                'error' => $body['errorMessage'] ?? $body['ResponseDescription'] ?? 'M-Pesa refused the request'];
    }

    return ['ok' => true, 'checkout_id' => $body['CheckoutRequestID'] ?? null, 'error' => null];
}
