<?php
/* Loads schema.sql and seed.sql into a hosted MySQL, over PDO.
 *
 *   php tools/load-remote-db.php
 *
 * This uses the same driver the website uses, so if it connects, the site
 * will connect too. It also handles MySQL 8.4's caching_sha2_password, which
 * older command-line clients refuse.
 *
 * The password is read without echoing, so it never reaches your shell
 * history or `ps`.
 */

if (PHP_SAPI !== 'cli') {
    exit("Command line only.\n");
}

/* readline() gives proper line editing, so a pasted host can be corrected
 * with backspace instead of leaving ^[[D escape codes in the value. */
function ask(string $label): string
{
    return trim((string) readline(str_pad($label, 36) . ': '));
}

/* The password is read with the terminal's echo switched off, so it never
 * appears on screen, in your shell history, or in `ps`. */
function ask_secret(string $label): string
{
    fwrite(STDOUT, str_pad($label, 36) . ': ');
    @shell_exec('stty -echo 2>/dev/null');
    $value = trim((string) fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");

    return $value;
}

/* The four non-secret values can come straight from the command line:
 *
 *   php tools/load-remote-db.php HOST PORT USER DATABASE
 *
 * which avoids retyping a long hostname. The password is always asked for. */
$args = array_slice($argv, 1);

$host = $args[0] ?? ask('Host     (mysql-x.aivencloud.com)');
$port = $args[1] ?? ask('Port     (25361)');
$user = $args[2] ?? ask('User     (avnadmin)');
$name = $args[3] ?? ask('Database (defaultdb)');
$pass = ask_secret('Password');

foreach (['host' => $host, 'port' => $port, 'user' => $user, 'name' => $name, 'pass' => $pass] as $k => $v) {
    if ($v === '') { exit("  $k is required.\n"); }
}

// A host pasted as "name:25361" still works.
if (str_contains($host, ':')) {
    [$host, $port] = explode(':', $host, 2);
}

$dsn = "mysql:host={$host};port=" . (int) $port . ";dbname={$name};charset=utf8mb4";

fwrite(STDOUT, "\n  Using  {$user}@{$host}:{$port}/{$name}\n\n  Connecting…\n");

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES             => false,
        // Aiven and friends require TLS. This encrypts without pinning the
        // CA, which is enough to load a schema from your own laptop.
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        PDO::ATTR_TIMEOUT                      => 15,
    ]);
} catch (Throwable $e) {
    fwrite(STDOUT, "  FAILED:\n\n    " . $e->getMessage() . "\n\n");

    $m = $e->getMessage();
    if (str_contains($m, 'Access denied')) {
        fwrite(STDOUT, "  Host and port are fine — the user or password is wrong.\n");
    } elseif (str_contains($m, 'Unknown MySQL server host') || str_contains($m, 'getaddrinfo')) {
        fwrite(STDOUT, "  The hostname did not resolve. Check it for typos.\n");
    } elseif (str_contains($m, "Can't connect") || str_contains($m, 'timed out')) {
        fwrite(STDOUT, "  Nothing answered on that port. Check the port, or your network.\n");
    } elseif (str_contains($m, 'Unknown database')) {
        fwrite(STDOUT, "  Connected, but there is no database by that name.\n");
    }
    exit(1);
}

fwrite(STDOUT, "  connected (" . $pdo->query('SELECT VERSION()')->fetchColumn() . ")\n\n");

/* Splits a .sql file into statements.
 *
 * Comments are stripped FIRST. seed.sql has a semicolon inside a comment
 * ("public/images/;"), and splitting before stripping cuts that comment in
 * half and silently swallows the statement after it — which is exactly how
 * the dish photos went missing once already. */
function statements(string $file): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));

    $out = [];
    foreach (explode(';', $sql) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk !== '') { $out[] = $chunk; }
    }

    return $out;
}

function load(PDO $pdo, string $file): void
{
    $stmts = statements($file);
    $done  = 0;

    foreach ($stmts as $sql) {
        try {
            $pdo->exec($sql);
            $done++;
        } catch (Throwable $e) {
            fwrite(STDOUT, "\n  Statement failed:\n    " . substr(preg_replace('/\s+/', ' ', $sql), 0, 90) . "…\n");
            fwrite(STDOUT, "    " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    fwrite(STDOUT, "  " . basename($file) . ": {$done} statements\n");
}

fwrite(STDOUT, "Loading…\n");
load($pdo, __DIR__ . '/../database/schema.sql');

// Only on an empty menu: seed.sql stops at the first duplicate row.
if ((int) $pdo->query('SELECT COUNT(*) FROM menu_items')->fetchColumn() === 0) {
    load($pdo, __DIR__ . '/../database/seed.sql');
} else {
    fwrite(STDOUT, "  seed.sql: skipped, menu_items already has rows\n");
}

$check = [
    'tables'      => ['SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()', 13],
    'dishes'      => ['SELECT COUNT(*) FROM menu_items', 12],
    'with photos' => ['SELECT COUNT(*) FROM menu_items WHERE image IS NOT NULL', 12],
    'categories'  => ['SELECT COUNT(*) FROM categories', 3],
    'room tables' => ['SELECT COUNT(*) FROM restaurant_tables', 19],
    'sessions'    => ["SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sessions'", 1],
];

fwrite(STDOUT, "\nChecking:\n");
$allOk = true;
foreach ($check as $label => [$sql, $want]) {
    $got = (int) $pdo->query($sql)->fetchColumn();
    $ok  = $got === $want;
    $allOk = $allOk && $ok;
    fwrite(STDOUT, sprintf("  %-12s %3d  expected %3d  %s\n", $label, $got, $want, $ok ? 'ok' : '<- WRONG'));
}

fwrite(STDOUT, "\n" . ($allOk
    ? "All good. Now set these in Vercel (Settings -> Environment Variables),\ntick Production, Preview and Development, then redeploy:\n\n"
    : "Something is off above — fix that before setting the variables.\n\n"));

fwrite(STDOUT, "  DB_HOST={$host}\n  DB_PORT={$port}\n  DB_NAME={$name}\n  DB_USER={$user}\n");
fwrite(STDOUT, "  DB_PASS=(the password you just typed)\n  DB_SSL=require\n");
