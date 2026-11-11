<?php
/* THE LIFE OF AN ORDER */

/* Which statuses an order can move to from where it is now. */
function order_next_statuses(string $current): array
{
    return match ($current) {
        'new'       => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        default     => [],      // completed and cancelled are final
    };
}


/* What the button for each move says, from the kitchen's point of view. */
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


/* Moves an order to a new status. */
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
            'INSERT INTO order_events (order_id, reference, from_status, to_status, actor)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$order['id'], $order['reference'], $from, $to, $actor]);

        $pdo->commit();

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('move_order failed: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'That did not save. Please try again.'];
    }

    // Telling the customer happens outside the transaction.
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


/* Marks a cash order as paid, from the till. */
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
            // No attempt was ever recorded.
            $pdo->prepare(
                'INSERT INTO payments (order_id, method, amount, status, provider_note)
                 VALUES (?, \'cash\', ?, \'pending\', "Taken at the counter")'
            )->execute([$order['id'], $order['total']]);

            $paymentId = (int) $pdo->lastInsertId();
            $method    = 'cash';
        } else {
            $paymentId = (int) $payment['id'];
            $method    = (string) $payment['method'];
        }

        // Record HOW it was paid as well as that it was.
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


/* The history of one order, oldest first. */
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


/* The orders the kitchen needs to see, with their dishes. */
function kitchen_orders(string $scope = 'open'): array
{
    // The account is joined in so the board can show whether this is a registered customer.
    $sql = 'SELECT o.*, u.name AS account_name, u.email AS account_email
              FROM orders o LEFT JOIN users u ON u.id = o.user_id ';

    $sql .= match ($scope) {
        'today'     => 'WHERE o.order_date = CURDATE() ',
        'cancelled' => "WHERE o.status = 'cancelled' ",
        // Done with: collected or called off. These live on history.php.
        'past'      => "WHERE o.status IN ('completed', 'cancelled') ",
        'all'       => '',
        default     => "WHERE o.status IN ('new','preparing','ready') ",
    };

    // Newest first: a kitchen screen is watched.
    $sql .= 'ORDER BY o.id DESC LIMIT 50';

    $orders = getPDO()->query($sql)->fetchAll();

    if (!$orders) {
        return [];
    }

    // One query for every line, rather than one per order.
    $ids   = array_column($orders, 'id');
    $holes = implode(',', array_fill(0, count($ids), '?'));

    $lines = getPDO()->prepare(
        "SELECT i.order_id, m.name, m.image, i.quantity, i.unit_price
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


/* Removes a cancelled order from the database for good. */
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

        /* Only an order that is finished with. A live one is still being
         * cooked or collected, and deleting it would lose work in progress. */
        if (!in_array($order['status'], ['completed', 'cancelled'], true)) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => sprintf(
                '%s is %s. Only a finished or cancelled order can be deleted.',
                $reference, $order['status']
            )];
        }

        // Checked on the orders row AND in the payments table.
        $paid = $pdo->prepare(
            "SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'paid'"
        );
        $paid->execute([$order['id']]);

        /* A CANCELLED order that was paid still owes the customer a refund,
         * and this row is the only record of the money — so it stays. A
         * COMPLETED paid order is a finished sale and may be cleared. */
        if ($order['status'] === 'cancelled'
            && ($order['payment_status'] === 'paid' || (int) $paid->fetchColumn() > 0)) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => sprintf(
                '%s was cancelled after it was paid, so it cannot be deleted — that would destroy the only record of the money. Refund it and keep the order.',
                $reference
            )];
        }

        // Written down BEFORE the row disappears.
        error_log(sprintf(
            'ORDER DELETED by %s: %s (%s, KSh %s, %s %s, placed %s, payment %s)',
            $actor, $reference, $order['name'], number_format((float) $order['total'], 0),
            $order['order_type'], $order['order_date'] . ' ' . $order['order_time'],
            $order['created_at'], $order['payment_status']
        ));

        /* Recorded BEFORE the row goes. order_events.order_id is SET NULL on
         * delete, so this event survives and the kitchen can still show that
         * the order was deleted, and by whom. */
        $pdo->prepare(
            'INSERT INTO order_events (order_id, reference, from_status, to_status, actor)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$order['id'], $order['reference'], $order['status'], 'deleted', $actor]);

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


/* Bookings for the kitchen screen, with the table each one is on. */
function kitchen_reservations(string $scope = 'upcoming'): array
{
    $sql = 'SELECT r.*, t.label AS table_label, t.seats AS table_seats, t.area AS table_area
              FROM reservations r
              LEFT JOIN restaurant_tables t ON t.id = r.table_id ';

    $sql .= match ($scope) {
        'today'     => 'WHERE r.reservation_date = CURDATE() ORDER BY r.reservation_time ASC ',
        'cancelled' => "WHERE r.status = 'cancelled' ORDER BY r.id DESC ",
        // Been and gone, or called off.
        'past'      => "WHERE (r.reservation_date < CURDATE() OR r.status = 'cancelled')
                        ORDER BY r.reservation_date DESC, r.reservation_time DESC ",
        'all'       => 'ORDER BY r.id DESC ',
        // A cancelled booking is not upcoming — its table is free again.
        default     => "WHERE r.reservation_date >= CURDATE() AND r.status = 'booked'
                        ORDER BY r.reservation_date ASC, r.reservation_time ASC ",
    };

    return getPDO()->query($sql . 'LIMIT 60')->fetchAll();
}


/* How many bookings are still to come. For the tab's counter. */
function upcoming_reservation_count(): int
{
    try {
        return (int) getPDO()->query(
            "SELECT COUNT(*) FROM reservations
              WHERE reservation_date >= CURDATE() AND status = 'booked'"
        )->fetchColumn();
    } catch (Throwable $ex) {
        error_log('upcoming_reservation_count: ' . $ex->getMessage());
        return 0;
    }
}


/* Cancellations and deletions the kitchen may not have noticed yet.
 *
 * A cancelled order leaves the live board, and a deleted one leaves the
 * database, so without this the kitchen could be cooking something the
 * customer already called off. Deleted orders survive here because
 * order_events.order_id is SET NULL rather than CASCADE. */
function kitchen_recent_exits(int $minutes = 180): array
{
    /* The cutoff is worked out in PHP and bound as a datetime. MySQL will
     * not accept a placeholder inside INTERVAL ? MINUTE in a real prepared
     * statement, though MariaDB allows it — so that form works locally and
     * fails on a hosted MySQL. */
    $cutoff = (new DateTimeImmutable())
        ->modify('-' . $minutes . ' minutes')
        ->format('Y-m-d H:i:s');

    $stmt = getPDO()->prepare(
        "SELECT e.reference, e.to_status, e.from_status, e.actor, e.created_at,
                (e.order_id IS NULL) AS order_gone
           FROM order_events e
          WHERE e.to_status IN ('cancelled', 'deleted')
            AND e.created_at >= ?
          ORDER BY e.id DESC
          LIMIT 12"
    );
    $stmt->execute([$cutoff]);

    return $stmt->fetchAll();
}


/* RESERVATIONS — the same three moves an order has: look, cancel, delete. */


/* One booking, with the table it is on. */
function get_reservation(int $id): ?array
{
    $stmt = getPDO()->prepare(
        'SELECT r.*, t.label AS table_label, t.seats AS table_seats, t.area AS table_area
           FROM reservations r
           LEFT JOIN restaurant_tables t ON t.id = r.table_id
          WHERE r.id = ?'
    );
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}


/* Every booking this visitor may see: their account's, plus any made as a
 * guest in this session. Newest first. */
function reservations_for_viewer(): array
{
    $user = current_user();
    $mine = array_values(array_filter(array_map('intval', $_SESSION['my_bookings'] ?? [])));

    $where = [];
    $args  = [];

    if ($user !== null) {
        $where[] = 'r.user_id = ?';
        $args[]  = (int) $user['id'];
    }
    if ($mine) {
        $where[] = 'r.id IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        $args    = array_merge($args, $mine);
    }

    if (!$where) {
        return [];
    }

    $stmt = getPDO()->prepare(
        'SELECT r.*, t.label AS table_label, t.seats AS table_seats, t.area AS table_area
           FROM reservations r
           LEFT JOIN restaurant_tables t ON t.id = r.table_id
          WHERE ' . implode(' OR ', $where) . '
          ORDER BY r.reservation_date DESC, r.reservation_time DESC'
    );
    $stmt->execute($args);

    return $stmt->fetchAll();
}


/* Does this booking belong to whoever is asking? */
function may_view_reservation(array $reservation): bool
{
    $user = current_user();

    if ($user !== null && (int) ($reservation['user_id'] ?? 0) === (int) $user['id']) {
        return true;
    }

    return in_array((int) $reservation['id'],
                    array_map('intval', $_SESSION['my_bookings'] ?? []), true);
}


/* Cancels a booking: the record stays, the table is freed.
 *
 * table_id goes NULL, and the unique key ignores NULLs, so the slot is
 * immediately bookable by somebody else. */
function cancel_reservation(int $id, string $actor = 'customer'): array
{
    $pdo = getPDO();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'That booking no longer exists.'];
        }

        if ($row['status'] === 'cancelled') {
            $pdo->rollBack();

            // Two taps on a slow connection, not an error.
            return ['ok' => true, 'error' => null];
        }

        $pdo->prepare("UPDATE reservations SET status = 'cancelled', table_id = NULL WHERE id = ?")
            ->execute([$id]);

        $pdo->commit();

        error_log(sprintf('RESERVATION CANCELLED by %s: #%d (%s, %s %s)',
            $actor, $id, $row['name'], $row['reservation_date'], $row['reservation_time']));

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('cancel_reservation: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'That did not cancel. Please try again.'];
    }

    return ['ok' => true, 'error' => null];
}


/* Removes a booking for good. Only one that is cancelled, or already past. */
function delete_reservation(int $id, string $actor = 'kitchen'): array
{
    $pdo = getPDO();

    try {
        $row = get_reservation($id);

        if (!$row) {
            return ['ok' => true, 'error' => null];   // already gone
        }

        $when = $row['reservation_date'] . ' ' . $row['reservation_time'];

        /* Any booking may be deleted, including one still to come — removing
         * the row frees its table by itself, because the unique key goes with
         * it. The confirm step lives in the page, not here. */
        error_log(sprintf('RESERVATION DELETED by %s: #%d (%s, %s, table %s, party of %s)',
            $actor, $id, $row['name'], $when, $row['table_label'] ?? '-', $row['party_size'] ?? '-'));

        $pdo->prepare('DELETE FROM reservations WHERE id = ?')->execute([$id]);

    } catch (Throwable $ex) {
        error_log('delete_reservation: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'That did not delete. Please try again.'];
    }

    return ['ok' => true, 'error' => null];
}
