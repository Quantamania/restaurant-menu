<?php
/* The toolbox: the database connection and the small helper functions that */


/* src/config.php, read once. */
function config(): array
{
    static $conf = null;

    if ($conf === null) {
        $file = __DIR__ . '/config.php';

        // Missing is not fatal.
        $conf = is_file($file) ? require $file : [];
    }

    return $conf;
}


/* Connects to the MySQL database and hands back the connection. */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = config()['database'] ?? [];

        // The environment wins, so a host that has no config file can still connect.
        $host = getenv('DB_HOST') ?: ($c['host']   ?? '');
        $name = getenv('DB_NAME') ?: ($c['dbname'] ?? '');
        $user = getenv('DB_USER') ?: ($c['user']   ?? '');
        $pass = getenv('DB_PASS') ?: ($c['pass']   ?? '');

        if ($host === '' || $name === '') {
            throw new RuntimeException(
                'No database configured. Copy src/config.example.php to '
              . 'src/config.php, or set DB_HOST / DB_NAME / DB_USER / DB_PASS.'
            );
        }

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Make the database agree with PHP about what time it is.
        $pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");
    }

    return $pdo;
}


/* Makes text safe to print inside a page. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}


/* Returns this visitor's secret form token, creating one if they have none. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}


/* Checks the token that came back with a submitted form. */
function csrf_valid(?string $token): bool
{
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}


/* Tables and availability */


/* Every table in the restaurant, with a flag saying whether it is already booked for t */
function getTablesWithAvailability(string $date, string $time): array
{
    $stmt = getPDO()->prepare(
        'SELECT t.id, t.label, t.seats, t.area,
                (r.id IS NOT NULL) AS is_taken
         FROM restaurant_tables t
         LEFT JOIN reservations r
                ON r.table_id = t.id
               AND r.reservation_date = ?
               AND r.reservation_time = ?
         WHERE t.is_active = 1
         ORDER BY t.seats, t.label'
    );
    $stmt->execute([$date, $time]);

    return $stmt->fetchAll();
}


/* One table by its id, or null if it does not exist or is switched off. */
function getTable(int $id): ?array
{
    $stmt = getPDO()->prepare(
        'SELECT id, label, seats, area FROM restaurant_tables WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}


/* Pre-ordering */


/* Every dish that can be ordered, grouped by category name. */
function getOrderableMenu(): array
{
    $rows = getPDO()->query(
        'SELECT i.id, i.name, i.description, i.price, i.image, c.name AS category_name
         FROM menu_items i
         JOIN categories c ON c.id = i.category_id
         WHERE i.is_available = 1
         ORDER BY c.display_order, i.name'
    )->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['category_name']][] = $row;
    }

    return $grouped;
}


/* Turns the quantity boxes from the form into lines we can save. */
function buildOrderLines(array $quantities, int $maxPerDish = 20): array
{
    $wanted = [];
    foreach ($quantities as $itemId => $qty) {
        $id  = (int) $itemId;
        $qty = (int) $qty;
        if ($id > 0 && $qty > 0) {
            $wanted[$id] = min($qty, $maxPerDish);
        }
    }

    if (!$wanted) {
        return ['lines' => [], 'subtotal' => 0.0, 'dropped' => []];
    }

    // "?, ?, ?" — one placeholder per dish, so the ids stay parameters.
    $marks = implode(',', array_fill(0, count($wanted), '?'));
    $stmt  = getPDO()->prepare(
        "SELECT id, name, description, price, image, is_available
         FROM menu_items WHERE id IN ($marks)"
    );
    $stmt->execute(array_keys($wanted));

    $lines    = [];
    $subtotal = 0.0;
    $found    = [];

    foreach ($stmt->fetchAll() as $row) {
        $id      = (int) $row['id'];
        $found[] = $id;

        if (!$row['is_available']) {
            $dropped[] = $row['name'];
            continue;
        }

        $qty       = $wanted[$id];
        $price     = (float) $row['price'];
        $subtotal += $price * $qty;

        $lines[] = [
            'menu_item_id' => $id,
            'name'         => $row['name'],
            'description'  => $row['description'],
            'image'        => $row['image'],
            'quantity'     => $qty,
            'unit_price'   => $price,
        ];
    }

    $dropped = $dropped ?? [];

    // An id that is not in the menu at all (a stale page, or a tampered form).
    $missing = count($wanted) - count($found);
    if ($missing > 0) {
        $dropped[] = $missing . ' item' . ($missing === 1 ? '' : 's') . ' no longer on the menu';
    }

    return ['lines' => $lines, 'subtotal' => $subtotal, 'dropped' => $dropped];
}


/* Makes the short code a customer quotes on the phone, like MD-7K3QB2. */
function makeOrderReference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $check    = getPDO()->prepare('SELECT 1 FROM orders WHERE reference = ?');

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $code = 'MD-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $check->execute([$code]);
        if (!$check->fetchColumn()) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate an order reference');
}


/* Looks an order up by its reference, with the dishes on it. */
function getOrderByReference(string $reference): ?array
{
    $stmt = getPDO()->prepare('SELECT * FROM orders WHERE reference = ?');
    $stmt->execute([strtoupper(trim($reference))]);
    $order = $stmt->fetch();

    if (!$order) {
        return null;
    }

    $items = getPDO()->prepare(
        'SELECT m.name, i.quantity, i.unit_price
         FROM order_items i JOIN menu_items m ON m.id = i.menu_item_id
         WHERE i.order_id = ? ORDER BY m.name'
    );
    $items->execute([$order['id']]);
    $order['items'] = $items->fetchAll();

    return $order;
}

/* The wordmark: the restaurant's name in capitals, with the first E drawn as three sta */
function wordmark(string $name, string $class = ''): string
{
    $upper = mb_strtoupper($name);
    $at    = mb_strpos($upper, 'E');

    $bars = '<span class="wordmark__e" aria-hidden="true">'
          . '<i></i><i></i><i></i></span>';

    if ($at === false) {
        $inner = '<span aria-hidden="true">' . e($upper) . '</span>';
    } else {
        $inner = '<span aria-hidden="true">' . e(mb_substr($upper, 0, $at)) . '</span>'
               . $bars
               . '<span aria-hidden="true">' . e(mb_substr($upper, $at + 1)) . '</span>';
    }

    return '<span class="wordmark ' . e($class) . '" role="img" aria-label="' . e($name) . '">'
         . $inner . '</span>';
}


/* The logo: the bitten box mark and the wordmark beside it. */
function brand_lockup(string $name, string $class = '', string $idSuffix = ''): string
{
    $bite = 'brand-bite' . ($idSuffix !== '' ? '-' . $idSuffix : '');
    $face = $bite . '-face';
    $initial = e(mb_strtoupper(mb_substr($name, 0, 1)));

    return '<a class="brand ' . e($class) . '" href="index.php" aria-label="' . e($name) . '">'
         . '<span class="brand__box-wrap">'
         // The square is drawn twice.
         . '<svg class="brand__box" viewBox="0 0 64 64" aria-hidden="true" focusable="false">'
         . '<mask id="' . $bite . '">'
         . '<rect width="64" height="64" fill="#fff"/>'
         . '<circle cx="60" cy="4" r="12" fill="#000"/>'
         . '</mask>'
         . '<mask id="' . $face . '">'
         . '<rect width="64" height="64" fill="#fff"/>'
         . '<circle cx="60" cy="4" r="13.6" fill="#000"/>'
         . '</mask>'
         . '<rect class="brand__edge" width="64" height="64" rx="18" mask="url(#' . $bite . ')"/>'
         . '<rect class="brand__face" x="1.6" y="1.6" width="60.8" height="60.8" rx="16.4"'
         . ' fill="currentColor" mask="url(#' . $face . ')"/>'
         . '</svg>'
         . '<span class="brand__mark" aria-hidden="true">' . $initial . '</span>'
         . '</span>'
         . wordmark($name, 'brand__word')
         . '</a>';
}


/* The trail of links showing where you are in the site. */
function breadcrumbs(array $trail): string
{
    if (count($trail) < 2) {
        // One entry means the home page, which has nowhere to go back to.
        return '';
    }

    $last = count($trail) - 1;
    $items = '';

    foreach ($trail as $i => [$label, $href]) {
        $isHere = $i === $last;

        $inner = $isHere || $href === null
            ? '<span class="crumbs__here" aria-current="page">' . e($label) . '</span>'
            : '<a class="crumbs__link" href="' . e($href) . '">' . e($label) . '</a>';

        // The separator is decoration, so it is hidden from a screen reader
        $sep = $isHere ? '' : '<span class="crumbs__sep" aria-hidden="true">/</span>';

        $items .= '<li class="crumbs__item">' . $inner . $sep . '</li>';
    }

    return '<nav class="crumbs" aria-label="Breadcrumb"><ol class="crumbs__list">'
         . $items . '</ol></nav>';
}
