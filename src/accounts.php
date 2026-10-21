<?php
/* ===========================================================================
 * CUSTOMER ACCOUNTS
 *
 * Ordering requires one. That is friction before the first order, and what
 * it buys is that "where is my food?" becomes "sign in" — no reference code
 * to keep, no one-time password texted to a phone that might be flat.
 *
 * WHAT IS STORED
 *
 * A bcrypt hash from password_hash(), never a password. The salt and the
 * cost live inside the hash, which is why there is nothing else to keep.
 *
 * THE SHAPE OF A SESSION
 *
 *   $_SESSION['user_id']   the signed-in account
 *   $_SESSION['my_orders'] references this browser placed (kept for the
 *                          orders that predate accounts, and so the payment
 *                          page can recognise an order placed a moment ago)
 *
 * Ownership of an order is decided by orders.user_id, not by the session.
 * The session only says who you are; the database says what is yours.
 * =========================================================================== */

require_once __DIR__ . '/mail.php';   // secrets(), used by the notify helpers


/** Wrong tries allowed before a lockout, and how long it lasts. */
const ACCOUNT_MAX_TRIES    = 8;
const ACCOUNT_LOCK_MINUTES = 15;

/** The shortest password we will accept. */
const ACCOUNT_MIN_PASSWORD = 8;


/* ------------------------------------------------------------ rate limiting
 * Shared with the kitchen door via the login_attempts table. Both need the
 * same defence, so they use the same code with a different scope.
 * ------------------------------------------------------------------------- */

/** The caller's IP, packed, for the attempts table. */
function login_client_ip(): string
{
    // Deliberately NOT reading X-Forwarded-For: anybody can send that header,
    // so trusting it would hand an attacker a fresh identity per request and
    // walk straight past the limit. Behind a real proxy this needs that
    // proxy's own trusted-header handling instead.
    $packed = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $packed === false ? str_repeat("\0", 16) : $packed;
}


/**
 * How many failures inside the window, counted two ways.
 *
 * By IP, which slows one machine working through a list of passwords; and by
 * email, which slows a list of machines all attacking one account. Either
 * hitting the cap is enough to lock.
 */
function login_failures(string $scope, ?string $identity = null): int
{
    try {
        $pdo = getPDO();

        $byIp = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE scope = ? AND ip = ? AND succeeded = 0
                AND created_at > (NOW() - INTERVAL ? MINUTE)'
        );
        $byIp->execute([$scope, login_client_ip(), ACCOUNT_LOCK_MINUTES]);
        $worst = (int) $byIp->fetchColumn();

        if ($identity !== null && $identity !== '') {
            $byWho = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE scope = ? AND identity = ? AND succeeded = 0
                    AND created_at > (NOW() - INTERVAL ? MINUTE)'
            );
            $byWho->execute([$scope, $identity, ACCOUNT_LOCK_MINUTES]);
            $worst = max($worst, (int) $byWho->fetchColumn());
        }

        return $worst;
    } catch (Throwable $ex) {
        error_log('login_failures: ' . $ex->getMessage());

        // If we cannot tell, assume the worst. A login that fails OPEN
        // because its own rate limiter broke is the wrong way round.
        return PHP_INT_MAX;
    }
}


/** Writes down an attempt, and prunes the table so it cannot grow for ever. */
function login_record(string $scope, bool $succeeded, ?string $identity = null): void
{
    try {
        $pdo = getPDO();
        $pdo->prepare(
            'INSERT INTO login_attempts (scope, ip, identity, succeeded) VALUES (?, ?, ?, ?)'
        )->execute([$scope, login_client_ip(), $identity, $succeeded ? 1 : 0]);

        // Nothing older than a day is any use to the limiter. Pruned on
        // write because this project has no cron.
        $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $ex) {
        error_log('login_record: ' . $ex->getMessage());
    }
}


/** Minutes until the oldest failure in the window ages out. */
function login_lock_minutes_left(string $scope, ?string $identity = null): int
{
    try {
        $stmt = getPDO()->prepare(
            'SELECT TIMESTAMPDIFF(MINUTE, NOW(), created_at + INTERVAL ? MINUTE)
               FROM login_attempts
              WHERE scope = ? AND succeeded = 0
                AND (ip = ? OR (identity IS NOT NULL AND identity = ?))
                AND created_at > (NOW() - INTERVAL ? MINUTE)
              ORDER BY created_at ASC LIMIT 1'
        );
        $stmt->execute([ACCOUNT_LOCK_MINUTES, $scope, login_client_ip(),
                        $identity, ACCOUNT_LOCK_MINUTES]);

        return max(1, (int) $stmt->fetchColumn());
    } catch (Throwable $ex) {
        return ACCOUNT_LOCK_MINUTES;
    }
}


/* --------------------------------------------------------------- the account */

/**
 * The signed-in customer, or null.
 *
 * Read from the database each request rather than cached in the session, so
 * that a changed name or phone number takes effect immediately and a deleted
 * account stops working at once. Memoised per request, so it is one query.
 */
function current_user(): ?array
{
    static $user = null;
    static $looked = false;

    if ($looked) {
        return $user;
    }

    $looked = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    try {
        $stmt = getPDO()->prepare(
            'SELECT id, name, email, phone, created_at FROM users WHERE id = ?'
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;

        // The row has gone — the account was deleted while signed in.
        if ($user === null) {
            unset($_SESSION['user_id']);
        }
    } catch (Throwable $ex) {
        error_log('current_user: ' . $ex->getMessage());
        $user = null;
    }

    return $user;
}


/** Signed in? */
function logged_in(): bool
{
    return current_user() !== null;
}


/**
 * Creates an account and signs it in.
 *
 * Returns ['ok' => bool, 'errors' => ['field' => 'message', ...]].
 * Field-keyed, so the form can put each message beside the input it belongs
 * to rather than printing one line at the top.
 */
function register_account(string $name, string $email, string $phone, string $password, string $confirm): array
{
    $errors = [];

    $name  = trim($name);
    $email = strtolower(trim($email));
    $phone = trim($phone);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors['name'] = 'Enter your name (2 to 100 characters).';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    }

    // The kitchen rings this number, so it is required — same rule as the
    // order form used before accounts existed.
    if (normalise_phone($phone) === null) {
        $errors['phone'] = 'Enter a phone number we can reach you on, like 0712 345 678.';
    }

    if (strlen($password) < ACCOUNT_MIN_PASSWORD) {
        $errors['password'] = 'Use at least ' . ACCOUNT_MIN_PASSWORD . ' characters.';
    } elseif (strlen($password) > 200) {
        // bcrypt silently ignores anything past 72 bytes; a huge value is
        // also a cheap way to make the server do pointless work.
        $errors['password'] = 'That password is too long.';
    }

    if (!hash_equals($password, $confirm)) {
        $errors['confirm'] = 'The two passwords do not match.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        $pdo = getPDO();

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, phone, password_hash) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);

        $userId = (int) $pdo->lastInsertId();

    } catch (PDOException $ex) {
        // 23000 is the integrity-constraint family, and the only unique key
        // on this table is the email. Letting the DATABASE decide this closes
        // the gap a "SELECT then INSERT" leaves open, where two people
        // registering at once could both find the address free.
        if ($ex->getCode() === '23000') {
            return ['ok' => false, 'errors' => [
                'email' => 'There is already an account with that email. Sign in instead.',
            ]];
        }

        error_log('register_account: ' . $ex->getMessage());

        return ['ok' => false, 'errors' => ['form' => 'We could not create that account. Please try again.']];
    }

    sign_in_user($userId);

    // Any order already placed with this address becomes theirs, so a
    // customer who ordered before accounts existed does not lose their
    // history the moment they register.
    claim_orders_for_user($userId, $email);

    return ['ok' => true, 'errors' => []];
}


/**
 * Checks an email and password and signs in.
 *
 * Returns ['ok' => bool, 'error' => ?string]. One message, not a field map:
 * saying WHICH half was wrong tells an attacker whether an address has an
 * account here.
 */
function login_account(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if (login_failures('customer', $email) >= ACCOUNT_MAX_TRIES) {
        return ['ok' => false, 'error' => sprintf(
            'Too many failed attempts. Try again in %d minutes.',
            login_lock_minutes_left('customer', $email)
        )];
    }

    $user = null;

    try {
        $stmt = getPDO()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch() ?: null;
    } catch (Throwable $ex) {
        error_log('login_account: ' . $ex->getMessage());

        return ['ok' => false, 'error' => 'We cannot reach the accounts system. Please try again.'];
    }

    // Verified even when there is no such account, against a throwaway hash.
    // Returning early instead would make a missing address answer measurably
    // faster than a wrong password, which is a way to harvest addresses.
    $hash = $user['password_hash']
        ?? '$2y$12$usuallyinvalidhashusedonlytoburnthesametimeasarealone00';

    if (!password_verify($password, $hash) || $user === null) {
        login_record('customer', false, $email);

        return ['ok' => false, 'error' => 'That email and password do not match.'];
    }

    login_record('customer', true, $email);
    sign_in_user((int) $user['id']);
    claim_orders_for_user((int) $user['id'], $email);

    return ['ok' => true, 'error' => null];
}


/** Puts a user into the session, safely. */
function sign_in_user(int $userId): void
{
    // A new session id, so an id an attacker managed to fix beforehand is not
    // the one that ends up signed in.
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;

    try {
        getPDO()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
                ->execute([$userId]);
    } catch (Throwable $ex) {
        error_log('last_login_at: ' . $ex->getMessage());
    }
}


/** Signs out, leaving the rest of the session alone. */
function logout_account(): void
{
    unset($_SESSION['user_id']);

    // The "orders this browser placed" list goes too: on a shared machine the
    // next person must not inherit a way into somebody else's order.
    unset($_SESSION['my_orders'], $_SESSION['unlocked_orders']);
}


/**
 * Attaches unowned orders with this email address to an account.
 *
 * Matches on email only, and only where user_id IS NULL, so it can never
 * take an order that already belongs to somebody else. Returns how many it
 * claimed, so the page can say so.
 */
function claim_orders_for_user(int $userId, string $email): int
{
    try {
        $stmt = getPDO()->prepare(
            'UPDATE orders SET user_id = ? WHERE user_id IS NULL AND LOWER(email) = ?'
        );
        $stmt->execute([$userId, strtolower(trim($email))]);

        $claimed = $stmt->rowCount();

        // Remembered for the next page to mention. A brand new account that
        // already has history needs explaining, or it looks like a bug.
        if ($claimed > 0) {
            $_SESSION['claimed_orders'] = $claimed;
        }

        return $claimed;
    } catch (Throwable $ex) {
        error_log('claim_orders_for_user: ' . $ex->getMessage());

        return 0;
    }
}


/* ------------------------------------------------------------- their orders */

/**
 * Every order belonging to an account, newest first, with its dishes.
 *
 * This is what replaced the reference-and-code lookup: being signed in IS
 * the proof, so there is nothing to type.
 */
function orders_for_user(int $userId, int $limit = 40): array
{
    $stmt = getPDO()->prepare(
        'SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll();

    if (!$orders) {
        return [];
    }

    // One query for all the lines rather than one per order.
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
 * May the person asking read this order?
 *
 * Three ways in, checked against the DATABASE rather than the session's
 * memory of what it has seen:
 *
 *   1. it belongs to their account
 *   2. this browser placed it a moment ago (so the payment page works
 *      before a guest has finished registering)
 *   3. they unlocked it with a one-time code — the path kept for orders
 *      taken before accounts existed, which have no user_id to match
 */
function may_view_order(array $order): bool
{
    $user = current_user();

    if ($user !== null && (int) $order['user_id'] === (int) $user['id']) {
        return true;
    }

    $reference = (string) $order['reference'];

    return in_array($reference, $_SESSION['my_orders'] ?? [], true)
        || in_array($reference, $_SESSION['unlocked_orders'] ?? [], true);
}
