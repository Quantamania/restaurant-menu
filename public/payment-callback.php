<?php
/* ===========================================================================
 * M-PESA CALLBACK
 *
 * Safaricom POSTs here when a customer has finished with the PIN prompt —
 * paid it, cancelled it, or let it time out. This is the only place that
 * learns whether money actually moved: the STK push itself only ever means
 * "the prompt was delivered".
 *
 * Three rules this endpoint lives by:
 *
 * 1. ALWAYS ANSWER 200. Safaricom retries anything else, for hours. If our
 *    database is down, a 500 would turn one failure into a flood — and the
 *    retries would not help, because the problem is at our end. We answer
 *    200, write the problem to the log, and deal with it ourselves.
 *
 * 2. BE SAFE TO RUN TWICE. A retried callback must not credit a payment
 *    twice or send two receipts. settle_payment() takes a row lock and
 *    returns early if the payment is already paid, and payments.provider_ref
 *    is UNIQUE, so a duplicate receipt number cannot be inserted at all.
 *
 * 3. TRUST NOTHING IN THE BODY except as a lookup key. The amount we charge
 *    is the one on our own order, never the number in the request — this URL
 *    is public, and anybody can POST to it.
 * =========================================================================== */

require __DIR__ . '/../src/setup.php';

// Answer first. Nothing below this point can change the reply, so a crash
// in our own code still leaves Safaricom with the 200 it needs.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

// Let the connection go, so Safaricom is not kept waiting while we write to
// the database and send a receipt.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);

    // Kept verbatim. When a payment is disputed weeks later, the provider's
    // original words are the only authority on what happened.
    error_log('mpesa callback: ' . substr($raw, 0, 2000));

    if (!is_array($body)) {
        error_log('mpesa callback: body was not JSON');
        return;
    }

    $stk = $body['Body']['stkCallback'] ?? null;

    if (!is_array($stk)) {
        error_log('mpesa callback: no stkCallback in body');
        return;
    }

    // Which payment this is about. The push put our own payment id in the
    // callback URL as ?p=, which is more reliable than matching on amount
    // and phone — two customers can owe the same amount from the same number.
    $paymentId = (int) ($_GET['p'] ?? 0);

    if ($paymentId <= 0) {
        // No id on the URL: fall back to the CheckoutRequestID we stored
        // when the push was accepted.
        $checkoutId = (string) ($stk['CheckoutRequestID'] ?? '');

        if ($checkoutId !== '') {
            $stmt = getPDO()->prepare(
                'SELECT id FROM payments WHERE provider_ref = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$checkoutId]);
            $paymentId = (int) $stmt->fetchColumn();
        }
    }

    if ($paymentId <= 0) {
        error_log('mpesa callback: could not match it to a payment');
        return;
    }

    // 0 is the only success. Everything else is a reason it did not happen:
    // 1032 is the customer pressing cancel, 1037 is the prompt timing out.
    $resultCode = (int) ($stk['ResultCode'] ?? -1);
    $resultDesc = (string) ($stk['ResultDesc'] ?? '');

    if ($resultCode !== 0) {
        mark_payment_failed($paymentId, $resultDesc !== '' ? $resultDesc : "ResultCode $resultCode");
        return;
    }

    // The metadata is a list of name/value pairs rather than an object, so
    // the receipt number has to be picked out of it.
    $receipt = null;
    foreach ($stk['CallbackMetadata']['Item'] ?? [] as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $receipt = (string) ($item['Value'] ?? '');
            break;
        }
    }

    // settle_payment does the locking, the idempotency check, and the
    // receipt email. All this file had to do was work out which payment.
    settle_payment($paymentId, $receipt, $resultDesc !== '' ? $resultDesc : null);

} catch (Throwable $ex) {
    // Swallowed on purpose: the 200 has already gone out, and the one thing
    // we must not do is let an exception turn into a retry storm.
    error_log('mpesa callback failed: ' . $ex->getMessage());
}
