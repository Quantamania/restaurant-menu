<?php
/* Runs the site's own write path against a hosted database and reports the
 * exact error, then rolls everything back so nothing is left behind.
 *
 *   php tools/diagnose-remote.php HOST PORT USER DATABASE
 *
 * Useful when a page says only "Sorry, we could not place your order" and the
 * real reason is sitting in a log you cannot easily read.
 */

if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }

$args = array_slice($argv, 1);
$host = $args[0] ?? trim((string) readline('Host     : '));
$port = $args[1] ?? trim((string) readline('Port     : '));
$user = $args[2] ?? trim((string) readline('User     : '));
$name = $args[3] ?? trim((string) readline('Database : '));

fwrite(STDOUT, str_pad('Password', 9) . ': ');
@shell_exec('stty -echo 2>/dev/null');
$pass = trim((string) fgets(STDIN));
@shell_exec('stty echo 2>/dev/null');
fwrite(STDOUT, "\n\n");

try {
    $pdo = new PDO(
        "mysql:host={$host};port=" . (int) $port . ";dbname={$name};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES             => false,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );
} catch (Throwable $e) {
    exit('  connection failed: ' . $e->getMessage() . "\n");
}

echo "  connected to MySQL " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n\n";

/* Settings that commonly differ between MariaDB and hosted MySQL and that
 * break perfectly good SQL. */
echo "Server settings:\n";
foreach (['sql_mode', 'time_zone', 'transaction_isolation', 'autocommit'] as $v) {
    try {
        $val = $pdo->query("SELECT @@{$v}")->fetchColumn();
        echo "  @@{$v} = " . ($val === '' ? "''" : $val) . "\n";
    } catch (Throwable $e) {
        echo "  @@{$v} — unavailable\n";
    }
}

/* The users row the order will hang off. */
$uid = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
echo "\n  users table has " . ($uid ? "a row (id {$uid})" : "NO ROWS") . "\n";
if (!$uid) { exit("  Cannot test an order without an account.\n"); }

$item = $pdo->query('SELECT id, price FROM menu_items ORDER BY id LIMIT 1')->fetch();

echo "\nReplaying the order write, inside a transaction that is rolled back:\n";

try {
    $pdo->beginTransaction();

    $ref = 'MD-TEST' . random_int(10, 99);

    echo "  1. INSERT INTO orders…\n";
    $pdo->prepare(
        'INSERT INTO orders
            (reference, user_id, name, email, phone, order_type, address,
             order_date, order_time, notes, subtotal, delivery_fee, total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $ref, $uid, 'Diagnostic', 'diag@example.test', '0700000000',
        'collection', null, date('Y-m-d'), '20:30:00', null,
        $item['price'], 0, $item['price'],
    ]);

    $orderId = (int) $pdo->lastInsertId();
    echo "     ok — new id {$orderId}\n";

    echo "  2. INSERT INTO order_items…\n";
    $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price)
         VALUES (?, ?, ?, ?)'
    )->execute([$orderId, $item['id'], 1, $item['price']]);
    echo "     ok\n";

    echo "  3. INSERT INTO order_events…\n";
    $pdo->prepare(
        "INSERT INTO order_events (order_id, from_status, to_status, actor)
         VALUES (?, NULL, 'new', 'customer')"
    )->execute([$orderId]);
    echo "     ok\n";

    $pdo->rollBack();
    echo "\n  All three inserts worked. Rolled back — nothing was saved.\n";
    echo "  So the write path is fine, and the failure is elsewhere.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }

    echo "\n  FAILED at the step above.\n";
    echo "  MySQL said:\n\n    " . $e->getMessage() . "\n\n";

    $m = $e->getMessage();
    if (str_contains($m, 'Unknown column')) {
        echo "  A column is missing — the schema on this server is older than the code.\n";
    } elseif (str_contains($m, "doesn't exist")) {
        echo "  A table is missing. Re-run tools/load-remote-db.php.\n";
    } elseif (str_contains($m, 'foreign key') || str_contains($m, 'a child row')) {
        echo "  A foreign key rejected it — a referenced row is missing.\n";
    } elseif (str_contains($m, 'Incorrect') || str_contains($m, 'Data too long')) {
        echo "  A value did not fit its column, probably because of strict SQL mode.\n";
    }
}

/* The other half: does the schema here match what the code expects? */
echo "\nSchema check:\n";
$expect = [
    'order_events' => ['order_id', 'reference', 'from_status', 'to_status', 'note', 'actor', 'created_at'],
    'orders'       => ['reference', 'user_id', 'order_type', 'status', 'payment_status', 'total'],
    'reservations' => ['reservation_date', 'reservation_time', 'table_id', 'party_size'],
    'sessions'     => ['id', 'payload', 'last_seen'],
];

foreach ($expect as $table => $cols) {
    try {
        $have = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $c) { $have[] = $c['Field']; }
        $missing = array_diff($cols, $have);
        printf("  %-14s %s\n", $table, $missing ? 'MISSING: ' . implode(', ', $missing) : 'ok');
    } catch (Throwable $e) {
        printf("  %-14s TABLE MISSING\n", $table);
    }
}
