<?php
/* ===========================================================================
 * STAFF SIGN-IN
 *
 * Guards the kitchen screen, which is the one page on this site that shows
 * every customer's name, phone number and home address at once. A guessed
 * URL reaching it is not an inconvenience, it is a data breach — so the
 * rules here are stricter than anywhere else in the project.
 *
 * WHAT IS STORED
 *
 * A hash, never a password. `staff.password_hash` in src/secrets.php holds
 * the output of password_hash(), which is a bcrypt digest with its own salt
 * built in. There is nowhere in the codebase, the database or the config
 * that a readable password exists.
 *
 * IF NO HASH IS CONFIGURED
 *
 * The screen refuses to open. It does NOT fall back to "no password
 * needed" — a half-installed site would then be publishing its customer
 * list. On a local machine it says how to set one; anywhere else it just
 * says the screen is not available, because the fix is not the visitor's
 * business.
 * =========================================================================== */

require_once __DIR__ . '/mail.php';   // for secrets()


/** How many wrong tries from one address before it is locked out, and for how long. */
const STAFF_MAX_TRIES   = 6;
const STAFF_LOCK_MINUTES = 15;


/** The configured bcrypt hash, or '' if the site has not been set up. */
function staff_password_hash(): string
{
    $conf = secrets();

    return (string) ($conf['staff']['password_hash'] ?? '');
}


/** Has somebody set a password at all? */
function staff_is_configured(): bool
{
    return staff_password_hash() !== '';
}


/**
 * Is a password demanded before the kitchen screen opens?
 *
 * Off via 'require_password' => false in src/secrets.php, which is how a
 * development machine gets straight to the board. See the note on that
 * setting for why it is on unless somebody turns it off on purpose.
 */
function staff_auth_required(): bool
{
    return (bool) (secrets()['staff']['require_password'] ?? true);
}


/** Is this request from a machine we would help with setup instructions? */
function staff_request_is_local(): bool
{
    $host = explode(':', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')))[0];

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.test');
}


/** May the caller see the board right now? */
function staff_logged_in(): bool
{
    // The password has been switched off, so everybody may. Checked first,
    // and nothing below it runs — no session to carry, nothing to expire.
    if (!staff_auth_required()) {
        return true;
    }

    if (empty($_SESSION['staff_since'])) {
        return false;
    }

    // A forgotten tablet on a counter should not stay signed in all night.
    // Eight hours covers a shift; after that it asks again.
    if (time() - (int) $_SESSION['staff_since'] > 8 * 3600) {
        staff_logout();
        return false;
    }

    return true;
}


/* Rate limiting lives in accounts.php and is shared with the customer door.
   Both need exactly the same defence — counted in the database, keyed on the
   IP, failing closed if the limiter itself breaks — so they use one
   login_attempts table with a different scope rather than two near-identical
   implementations. Only the numbers differ. */

/** Locked out? */
function staff_is_locked_out(): bool
{
    return login_failures('staff') >= STAFF_MAX_TRIES;
}


/** Minutes until the lockout lifts, for the message shown. */
function staff_lock_minutes_left(): int
{
    return login_lock_minutes_left('staff');
}



/**
 * Checks a typed password and signs in if it is right.
 *
 * Returns ['ok' => bool, 'error' => ?string].
 */
function staff_login(string $typed): array
{
    if (!staff_is_configured()) {
        return ['ok' => false, 'error' => 'This screen has not been set up yet.'];
    }

    if (staff_is_locked_out()) {
        return ['ok' => false, 'error' => sprintf(
            'Too many wrong tries. Try again in %d minutes.', staff_lock_minutes_left()
        )];
    }

    // password_verify does the constant-time comparison itself, and reads the
    // cost and salt out of the stored hash.
    if (!password_verify($typed, staff_password_hash())) {
        login_record('staff', false);

        $left = STAFF_MAX_TRIES - login_failures('staff');

        return ['ok' => false, 'error' => $left > 0
            ? sprintf('That is not the right password. %d %s left.',
                      $left, $left === 1 ? 'try' : 'tries')
            : 'That is not the right password.'];
    }

    login_record('staff', true);

    // A new session id on sign-in, so a session id an attacker managed to
    // fix beforehand is not the one that ends up signed in.
    session_regenerate_id(true);

    $_SESSION['staff_since'] = time();

    return ['ok' => true, 'error' => null];
}


/** Signs out, without disturbing the customer-facing parts of the session. */
function staff_logout(): void
{
    unset($_SESSION['staff_since']);
}


/* =================================================== order status changes ==
 * The kitchen screen's whole job. Kept here rather than in the page so the
 * rules about which move is allowed live in one place, and so a future
 * phone app or till would get the same behaviour for free.
 * ========================================================================= */

/**
 * Which statuses an order can move to from where it is now.
 *
 * Only forwards, plus cancel. There is no going back: an order that has
 * been handed to a customer cannot become "being cooked" again, and a
 * screen that allows it invites a mis-tap to rewrite history. A genuine
 * mistake is a new order, or a cancellation with a note.
 */
function order_next_statuses(string $current): array
{
    return match ($current) {
        'new'       => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        default     => [],      // completed and cancelled are final
    };
}


/** What the button for each move says, from the kitchen's point of view. */
function order_action_label(string $to, string $orderType): string
{
    return match ($to) {
        'preparing' => 'Start cooking',
        'ready'     => 'Mark ready',
        'completed' => $orderType === 'delivery' ? 'Mark delivered' : 'Mark collected',
        'cancelled' => 'Cancel',
        default     => $to,
    };
}


/**
 * Moves an order to a new status.
 *
 * Returns ['ok' => bool, 'error' => ?string].
 *
 * The transition is re-checked against the row as it is in the database,
 * inside a lock — not against whatever the page was showing. Two cooks on
 * two tablets will both have a stale screen eventually, and the second tap
 * must not be able to drag an order backwards.
 */
function move_order(string $reference, string $to, string $actor = 'kitchen'): array
{
    $pdo = getPDO();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE reference = ? FOR UPDATE');
        $stmt->execute([$reference]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That order no longer exists.'];
        }

        $from = (string) $order['status'];

        // Already there. Not an error — two taps on a slow connection.
        if ($from === $to) {
            $pdo->rollBack();
            return ['ok' => true, 'error' => null];
        }

        if (!in_array($to, order_next_statuses($from), true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => sprintf(
                'Order %s is already %s, so it cannot be moved to %s.',
                $reference, $from, $to
            )];
        }

        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')
            ->execute([$to, $order['id']]);

        // The history and the status move together or not at all.
        $pdo->prepare(
            'INSERT INTO order_events (order_id, from_status, to_status, actor)
             VALUES (?, ?, ?, ?)'
        )->execute([$order['id'], $from, $to, $actor]);

        $pdo->commit();

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('move_order failed: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'That did not save. Please try again.'];
    }

    // Telling the customer happens outside the transaction: it is slow, it
    // can fail, and neither should hold a row lock or undo the move.
    try {
        $full = getOrderByReference($reference);

        if ($full) {
            notify_order_status($full, $to);
        }
    } catch (Throwable $ex) {
        error_log('status notification failed: ' . $ex->getMessage());
    }

    return ['ok' => true, 'error' => null];
}


/**
 * Marks a cash order as paid, from the till.
 *
 * Separate from move_order() because money and cooking are separate states
 * (see the comment on the orders table). Handing over food and taking the
 * cash are two different moments, and on a busy counter they happen in
 * either order.
 */
function mark_order_paid(string $reference, string $actor = 'kitchen'): array
{
    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE reference = ?');
        $stmt->execute([$reference]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['ok' => false, 'error' => 'That order no longer exists.'];
        }

        if ($order['payment_status'] === 'paid') {
            return ['ok' => true, 'error' => null];
        }

        $payment = latest_payment((int) $order['id']);

        if (!$payment) {
            // No attempt was ever recorded — somebody is paying at the
            // counter for an order that skipped the payment screen. Make the
            // row now, so the money still has a record of its own.
            $pdo->prepare(
                'INSERT INTO payments (order_id, method, amount, status, provider_note)
                 VALUES (?, "cash", ?, "pending", "Taken at the counter")'
            )->execute([$order['id'], $order['total']]);

            $paymentId = (int) $pdo->lastInsertId();
            $method    = 'cash';
        } else {
            $paymentId = (int) $payment['id'];
            $method    = (string) $payment['method'];
        }

        // Record HOW it was paid as well as that it was. settle_payment only
        // sets payment_status, because it is also reached from the M-Pesa
        // callback where the method was already written when the push went
        // out. An order that skipped the payment screen has no method yet,
        // and "paid, by nothing" is a state that then shows up as a blank on
        // both the receipt and the board.
        if (empty($order['payment_method'])) {
            $pdo->prepare('UPDATE orders SET payment_method = ? WHERE id = ?')
                ->execute([$method, $order['id']]);
        }

        // settle_payment does the locking, the idempotency and the receipt.
        $ok = settle_payment($paymentId, null, 'Confirmed by ' . $actor);

        return ['ok' => $ok, 'error' => $ok ? null : 'That did not save. Please try again.'];

    } catch (Throwable $ex) {
        error_log('mark_order_paid failed: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'That did not save. Please try again.'];
    }
}


/** The history of one order, oldest first. */
function order_history(int $orderId): array
{
    try {
        $stmt = getPDO()->prepare(
            'SELECT * FROM order_events WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    } catch (Throwable $ex) {
        error_log('order_history: ' . $ex->getMessage());

        return [];
    }
}


/**
 * The orders the kitchen needs to see, with their dishes.
 *
 * $scope 'open'  — everything not finished or cancelled, any date. The
 *                  default, because an order for tomorrow that nobody has
 *                  started is exactly what must not be forgotten.
 *        'today' — everything due today, finished ones included
 *        'all'   — the last 50, for looking something up
 */
function kitchen_orders(string $scope = 'open'): array
{
    // The account is joined in so the board can show whether this is a
    // registered customer and which account it belongs to — the same order,
    // seen from the kitchen's side.
    $sql = 'SELECT o.*, u.name AS account_name, u.email AS account_email
              FROM orders o LEFT JOIN users u ON u.id = o.user_id ';

    $sql .= match ($scope) {
        'today' => 'WHERE o.order_date = CURDATE() ',
        'all'   => '',
        default => 'WHERE o.status IN ("new","preparing","ready") ',
    };

    // Soonest first: the kitchen works in the order food is needed, not the
    // order it was typed in.
    $sql .= 'ORDER BY o.order_date ASC, o.order_time ASC, o.id ASC LIMIT 50';

    $orders = getPDO()->query($sql)->fetchAll();

    if (!$orders) {
        return [];
    }

    // One query for every line, rather than one per order.
    $ids   = array_column($orders, 'id');
    $holes = implode(',', array_fill(0, count($ids), '?'));

    $lines = getPDO()->prepare(
        "SELECT i.order_id, m.name, i.quantity, i.unit_price
           FROM order_items i JOIN menu_items m ON m.id = i.menu_item_id
          WHERE i.order_id IN ($holes)
          ORDER BY m.name"
    );
    $lines->execute($ids);

    $byOrder = [];
    foreach ($lines->fetchAll() as $line) {
        $byOrder[$line['order_id']][] = $line;
    }

    foreach ($orders as &$order) {
        $order['items'] = $byOrder[$order['id']] ?? [];
    }

    return $orders;
}


/**
 * Removes a cancelled order from the database for good.
 *
 * ONLY a cancelled one. A live order is the kitchen's work list and a
 * finished one is the restaurant's trading record; neither should be
 * removable with a tap. Cancelled orders are the ones that accumulate as
 * clutter on the board, so they are the ones worth clearing.
 *
 * WHAT GOES WITH IT
 *
 * The foreign keys cascade, so this also deletes the order's dishes, its
 * payment attempts, its status history and any tracking codes. The outbox
 * (notifications) is ON DELETE SET NULL, so the record of what was emailed
 * and texted survives with its order_id blanked — a customer can still be
 * shown what they were sent.
 *
 * WHY A PAID ORDER IS REFUSED
 *
 * payments cascades. Deleting an order that took money would destroy the
 * only record of that money, and a cancelled-but-paid order is precisely the
 * one that still owes somebody a refund. So it is refused, with a reason,
 * rather than quietly doing it.
 *
 * Returns ['ok' => bool, 'error' => ?string].
 */
function delete_order(string $reference, string $actor = 'kitchen'): array
{
    $pdo = getPDO();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE reference = ? FOR UPDATE');
        $stmt->execute([$reference]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();

            // Already gone. Two taps on a slow connection, not an error.
            return ['ok' => true, 'error' => null];
        }

        if ($order['status'] !== 'cancelled') {
            $pdo->rollBack();

            return ['ok' => false, 'error' => sprintf(
                'Only a cancelled order can be deleted. %s is %s — cancel it first.',
                $reference, $order['status']
            )];
        }

        // Checked on the orders row AND in the payments table: the summary
        // column could be out of step, and where money is concerned the
        // detailed record is the one to believe.
        $paid = $pdo->prepare(
            'SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = "paid"'
        );
        $paid->execute([$order['id']]);

        if ($order['payment_status'] === 'paid' || (int) $paid->fetchColumn() > 0) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => sprintf(
                '%s was paid, so it cannot be deleted — that would destroy the only record of the money. Refund it first and keep the order.',
                $reference
            )];
        }

        // Written down BEFORE the row disappears. order_events cascades, so
        // after this the database holds no trace of the order at all; the
        // log becomes the only record that it ever existed.
        error_log(sprintf(
            'ORDER DELETED by %s: %s (%s, KSh %s, %s %s, placed %s, payment %s)',
            $actor, $reference, $order['name'], number_format((float) $order['total'], 0),
            $order['order_type'], $order['order_date'] . ' ' . $order['order_time'],
            $order['created_at'], $order['payment_status']
        ));

        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);

        $pdo->commit();

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('delete_order failed: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'That did not delete. Please try again.'];
    }

    return ['ok' => true, 'error' => null];
}
