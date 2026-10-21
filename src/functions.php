<?php
/* ===========================================================================
 * The toolbox: the database connection and the small helper functions that
 * every page uses. Loaded by src/setup.php.
 * =========================================================================== */


/**
 * Connects to the MySQL database and hands back the connection.
 *
 * The first call opens the connection; every call after that reuses the same
 * one, so a page only ever connects once. Settings come from src/config.php.
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $file = __DIR__ . '/config.php';

        if (!is_file($file)) {
            throw new RuntimeException('Copy src/config.sample.php to src/config.php');
        }

        $c   = require $file;
        $dsn = "mysql:host={$c['host']};dbname={$c['dbname']};charset=utf8mb4";

        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Make the database agree with PHP about what time it is. Otherwise a
        // server in one zone and a database in another disagree about when an
        // order was placed, and created_at drifts from the restaurant's clock.
        $pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");
    }

    return $pdo;
}


/**
 * Makes text safe to print inside a page.
 *
 * ALWAYS use this around anything that came from the database or from a
 * visitor: <?= e($item['name']) ?>. Without it, someone could type HTML or
 * JavaScript into the reservation form and have it run on your page.
 */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}


/**
 * Returns this visitor's secret form token, creating one if they have none.
 *
 * Every form must print it in a hidden field. It proves the form was really
 * sent from our own page, and not from somebody else's.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}


/**
 * Checks the token that came back with a submitted form.
 */
function csrf_valid(?string $token): bool
{
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}


/* ---------------------------------------------------------------------------
 * Tables and availability
 * --------------------------------------------------------------------------- */


/**
 * Every table in the restaurant, with a flag saying whether it is already
 * booked for the date and time given.
 *
 * It asks the database for all active tables, and LEFT JOINs the reservations
 * for that one slot. If the join finds a reservation, the table is taken.
 *
 * Returns a list of rows: id, label, seats, area, is_taken.
 */
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


/**
 * One table by its id, or null if it does not exist or is switched off.
 * Used to re-check the chosen table on the server before saving.
 */
function getTable(int $id): ?array
{
    $stmt = getPDO()->prepare(
        'SELECT id, label, seats, area FROM restaurant_tables WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}


/* ---------------------------------------------------------------------------
 * Pre-ordering
 * --------------------------------------------------------------------------- */


/**
 * Every dish that can be ordered, grouped by category name.
 */
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


/**
 * Turns the quantity boxes from the form into lines we can save.
 *
 * $quantities arrives from the browser and cannot be trusted, so the price of
 * every dish is looked up in the database here. Nothing a visitor types is
 * ever used as a price.
 *
 * If a dish has sold out since the page was drawn it cannot be ordered — but
 * it is NOT dropped quietly, because the customer would be charged less than
 * they asked for and find out at the counter. It comes back under 'dropped'
 * so order.php can say which dish went and make them look again.
 *
 * Returns ['lines' => [...], 'subtotal' => float, 'dropped' => ['name', ...]].
 */
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


/**
 * Makes the short code a customer quotes on the phone, like MD-7K3QB2.
 *
 * Letters that are easy to confuse when read aloud or written down (I, O, 0,
 * 1) are left out. It retries if the code is already taken; the UNIQUE key on
 * orders.reference is what makes that impossible to get wrong.
 *
 * The prefix is MD for Maridadi. Orders taken before the restaurant was
 * renamed carry the old ZW- and keep it: a reference is looked up as an
 * exact string, so an old one still resolves. Rewriting them would break
 * every receipt and confirmation email already in a customer's inbox.
 */
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


/**
 * Looks an order up by its reference, with the dishes on it.
 * Returns null if there is no such order.
 */
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

/**
 * The wordmark: the restaurant's name in capitals, with the first E drawn as
 * three stacked bars instead of a letter — the "menu lines" idea from the
 * logo sketch.
 *
 * The bars are spans, not a character, so the shape is identical in every
 * browser whatever font is loaded. The whole thing carries an aria-label so
 * a screen reader still hears the plain name.
 */
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


/**
 * The logo: the bitten box mark and the wordmark beside it.
 *
 * Lifted out of header.php so the kitchen screen can show the same lockup
 * without the markup being copied. Two copies would drift apart, and the
 * SVG mask ids would collide.
 *
 * $idSuffix makes the mask ids unique. An id has to be unique in a document,
 * and a masked shape that points at a duplicate id renders against whichever
 * one the browser found first — so a second lockup on one page would quietly
 * lose its bite.
 */
function brand_lockup(string $name, string $class = '', string $idSuffix = ''): string
{
    $bite = 'brand-bite' . ($idSuffix !== '' ? '-' . $idSuffix : '');
    $face = $bite . '-face';
    $initial = e(mb_strtoupper(mb_substr($name, 0, 1)));

    return '<a class="brand ' . e($class) . '" href="index.php" aria-label="' . e($name) . '">'
         . '<span class="brand__box-wrap">'
         // The square is drawn twice: an outline, then the face 1.6 units
         // inside it with a correspondingly wider bite, so the outline
         // follows the curve of the bite instead of cutting a square corner
         // across it the way a CSS ring would.
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


/**
 * The trail of links showing where you are in the site.
 *
 * $trail is a list of [label, href] pairs, in order from the home page
 * inwards. The LAST entry is where you are now, so its href is ignored and
 * it is rendered as plain text — a link to the page you are already on is
 * noise, and a screen reader announces it as somewhere to go.
 *
 *     breadcrumbs([['Home', 'index.php'], ['Menu', 'menu.php'], ['Order', null]])
 *
 * Marked up as a <nav> wrapping an ordered list, because the order is the
 * meaning, and with aria-current="page" on the last item so assistive
 * software can say "you are here" rather than leaving it to the styling.
 */
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
        // — otherwise every step is read out as "slash".
        $sep = $isHere ? '' : '<span class="crumbs__sep" aria-hidden="true">/</span>';

        $items .= '<li class="crumbs__item">' . $inner . $sep . '</li>';
    }

    return '<nav class="crumbs" aria-label="Breadcrumb"><ol class="crumbs__list">'
         . $items . '</ol></nav>';
}
