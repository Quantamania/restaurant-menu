<?php
/* WHO YOU ARE — customer accounts and the staff door */


/* PART 1 — RATE LIMITING, shared by both doors */

/* Wrong tries allowed before a lockout, and how long it lasts. */
const ACCOUNT_MAX_TRIES    = 8;
const ACCOUNT_LOCK_MINUTES = 15;

/* The shortest password we will accept. */
const ACCOUNT_MIN_PASSWORD = 8;


/* ------------------------------------------------------------ rate limiting */

/* The caller's IP, packed, for the attempts table. */
function login_client_ip(): string
{
    // Deliberately NOT reading X-Forwarded-For.
    $packed = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $packed === false ? str_repeat("\0", 16) : $packed;
}


/* The start of the lockout window, as a datetime the database can compare. */
function login_window_start(): string
{
    return (new DateTimeImmutable())
        ->modify('-' . ACCOUNT_LOCK_MINUTES . ' minutes')
        ->format('Y-m-d H:i:s');
}


/* How many failures inside the window, counted two ways. */
function login_failures(string $scope, ?string $identity = null): int
{
    try {
        $pdo = getPDO();

        /* The cutoff is computed here, not in SQL. MySQL rejects a
         * placeholder inside INTERVAL ? MINUTE in a real prepared statement,
         * though MariaDB allows it — so the SQL form works locally and
         * silently breaks the lockout on a hosted MySQL. */
        $since = login_window_start();

        $byIp = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE scope = ? AND ip = ? AND succeeded = 0
                AND created_at > ?'
        );
        $byIp->execute([$scope, login_client_ip(), $since]);
        $worst = (int) $byIp->fetchColumn();

        if ($identity !== null && $identity !== '') {
            $byWho = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE scope = ? AND identity = ? AND succeeded = 0
                    AND created_at > ?'
            );
            $byWho->execute([$scope, $identity, $since]);
            $worst = max($worst, (int) $byWho->fetchColumn());
        }

        return $worst;
    } catch (Throwable $ex) {
        error_log('login_failures: ' . $ex->getMessage());

        // If we cannot tell, assume the worst.
        return PHP_INT_MAX;
    }
}


/* Writes down an attempt, and prunes the table so it cannot grow for ever. */
function login_record(string $scope, bool $succeeded, ?string $identity = null): void
{
    try {
        $pdo = getPDO();
        $pdo->prepare(
            'INSERT INTO login_attempts (scope, ip, identity, succeeded) VALUES (?, ?, ?, ?)'
        )->execute([$scope, login_client_ip(), $identity, $succeeded ? 1 : 0]);

        // Nothing older than a day is any use to the limiter.
        $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $ex) {
        error_log('login_record: ' . $ex->getMessage());
    }
}


/* Minutes until the oldest failure in the window ages out. */
function login_lock_minutes_left(string $scope, ?string $identity = null): int
{
    try {
        $stmt = getPDO()->prepare(
            'SELECT created_at FROM login_attempts
              WHERE scope = ? AND succeeded = 0
                AND (ip = ? OR (identity IS NOT NULL AND identity = ?))
                AND created_at > ?
              ORDER BY created_at ASC LIMIT 1'
        );
        $stmt->execute([$scope, login_client_ip(), $identity, login_window_start()]);

        $oldest = $stmt->fetchColumn();
        if (!$oldest) {
            return ACCOUNT_LOCK_MINUTES;
        }

        // When that attempt ages out of the window.
        $free = (new DateTimeImmutable((string) $oldest))
            ->modify('+' . ACCOUNT_LOCK_MINUTES . ' minutes');

        $left = (int) ceil(($free->getTimestamp() - time()) / 60);

        return max(1, $left);
    } catch (Throwable $ex) {
        return ACCOUNT_LOCK_MINUTES;
    }
}


/* --------------------------------------------------------------- the account */

/* The signed-in customer, or null. */
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


/* Signed in? */
function logged_in(): bool
{
    return current_user() !== null;
}


/* Creates an account and signs it in. */
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

    // The kitchen rings this number, so it is required.
    if (normalise_phone($phone) === null) {
        $errors['phone'] = 'Enter a phone number we can reach you on, like 0712 345 678.';
    }

    if (strlen($password) < ACCOUNT_MIN_PASSWORD) {
        $errors['password'] = 'Use at least ' . ACCOUNT_MIN_PASSWORD . ' characters.';
    } elseif (strlen($password) > 200) {
        // bcrypt silently ignores anything past 72 bytes.
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
        // 23000 is the integrity-constraint family.
        if ($ex->getCode() === '23000') {
            return ['ok' => false, 'errors' => [
                'email' => 'There is already an account with that email. Sign in instead.',
            ]];
        }

        error_log('register_account: ' . $ex->getMessage());

        return ['ok' => false, 'errors' => ['form' => 'We could not create that account. Please try again.']];
    }

    sign_in_user($userId);

    // Any order already placed with this address becomes theirs.
    claim_orders_for_user($userId, $email);

    return ['ok' => true, 'errors' => []];
}


/* Checks an email and password and signs in. */
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


/* Puts a user into the session, safely. */
function sign_in_user(int $userId): void
{
    // A new session id, so an id an attacker managed to fix beforehand is not the one that.
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;

    try {
        getPDO()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
                ->execute([$userId]);
    } catch (Throwable $ex) {
        error_log('last_login_at: ' . $ex->getMessage());
    }
}


/* Signs out, leaving the rest of the session alone. */
function logout_account(): void
{
    unset($_SESSION['user_id']);

    // The "orders this browser placed" list goes too.
    unset($_SESSION['my_orders'], $_SESSION['unlocked_orders']);
}


/* Attaches unowned orders with this email address to an account. */
function claim_orders_for_user(int $userId, string $email): int
{
    try {
        $stmt = getPDO()->prepare(
            'UPDATE orders SET user_id = ? WHERE user_id IS NULL AND LOWER(email) = ?'
        );
        $stmt->execute([$userId, strtolower(trim($email))]);

        $claimed = $stmt->rowCount();

        // Remembered for the next page to mention.
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

/* Every order belonging to an account, newest first, with its dishes. */
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
        // m.image comes along so the list can show the dish, not just name it.
        "SELECT i.order_id, m.name, m.image, m.image_alt, i.quantity, i.unit_price
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


/* May the person asking read this order? */
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


/* PART 2 — THE STAFF DOOR */

/* How many wrong tries from one address before it is locked out, and for how long. */
const STAFF_MAX_TRIES   = 6;
const STAFF_LOCK_MINUTES = 15;


/* The configured bcrypt hash, or '' if the site has not been set up. */
function staff_password_hash(): string
{
    $conf = secrets();

    return (string) ($conf['staff']['password_hash'] ?? '');
}


/* Has somebody set a password at all? */
function staff_is_configured(): bool
{
    return staff_password_hash() !== '';
}


/* Is a password demanded before the kitchen screen opens? */
function staff_auth_required(): bool
{
    return (bool) (secrets()['staff']['require_password'] ?? true);
}


/* Is this request from a machine we would help with setup instructions? */
function staff_request_is_local(): bool
{
    $host = explode(':', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')))[0];

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.test');
}


/* May the caller see the board right now? */
function staff_logged_in(): bool
{
    // The password has been switched off, so everybody may.
    if (!staff_auth_required()) {
        return true;
    }

    if (empty($_SESSION['staff_since'])) {
        return false;
    }

    // A forgotten tablet on a counter should not stay signed in all night.
    if (time() - (int) $_SESSION['staff_since'] > 8 * 3600) {
        staff_logout();
        return false;
    }

    return true;
}


/* Rate limiting lives in Part 1 above and is shared with the customer door. */

/* Locked out? */
function staff_is_locked_out(): bool
{
    return login_failures('staff') >= STAFF_MAX_TRIES;
}


/* Minutes until the lockout lifts, for the message shown. */
function staff_lock_minutes_left(): int
{
    return login_lock_minutes_left('staff');
}



/* Checks a typed password and signs in if it is right. */
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

    // password_verify does the constant-time comparison itself.
    if (!password_verify($typed, staff_password_hash())) {
        login_record('staff', false);

        $left = STAFF_MAX_TRIES - login_failures('staff');

        return ['ok' => false, 'error' => $left > 0
            ? sprintf('That is not the right password. %d %s left.',
                      $left, $left === 1 ? 'try' : 'tries')
            : 'That is not the right password.'];
    }

    login_record('staff', true);

    // A new session id on sign-in.
    session_regenerate_id(true);

    $_SESSION['staff_since'] = time();

    return ['ok' => true, 'error' => null];
}


/* Signs out, without disturbing the customer-facing parts of the session. */
function staff_logout(): void
{
    unset($_SESSION['staff_since']);
}
