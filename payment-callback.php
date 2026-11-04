<?php
/* M-PESA CALLBACK */

require __DIR__ . '/includes/setup.php';

// Answer first.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

// Let the connection go.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);

    // Kept verbatim.
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

    // Which payment this is about.
    $paymentId = (int) ($_GET['p'] ?? 0);

    if ($paymentId <= 0) {
        // No id on the URL: fall back to the CheckoutRequestID we stored when the push was.
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
    $resultCode = (int) ($stk['ResultCode'] ?? -1);
    $resultDesc = (string) ($stk['ResultDesc'] ?? '');

    if ($resultCode !== 0) {
        mark_payment_failed($paymentId, $resultDesc !== '' ? $resultDesc : "ResultCode $resultCode");
        return;
    }

    // The metadata is a list of name/value pairs rather than an object.
    $receipt = null;
    foreach ($stk['CallbackMetadata']['Item'] ?? [] as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $receipt = (string) ($item['Value'] ?? '');
            break;
        }
    }

    // settle_payment does the locking, the idempotency check, and the receipt email.
    settle_payment($paymentId, $receipt, $resultDesc !== '' ? $resultDesc : null);

} catch (Throwable $ex) {
    // Swallowed on purpose.
    error_log('mpesa callback failed: ' . $ex->getMessage());
}
