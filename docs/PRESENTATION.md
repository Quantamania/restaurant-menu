# Maridadi Eat — how this project works

A step-by-step walkthrough of a restaurant website written in plain PHP and
MySQL. Read in order, each step builds on the one before it.

**What it does:** a customer reads the menu, pre-orders food, pays, and tracks
the order. They can also book a table. The kitchen has a separate screen where
orders move through the stages. Everything the customer does appears on the
kitchen screen, and everything the kitchen does appears back on the customer's
page.

**What it is built from:** PHP 8, MySQL/MariaDB, CSS, and a little JavaScript.
No framework, no Composer, no npm, no build step. Every file in this project is
one I can open and explain.

---

## Step 1 — The file system

The layout is the ordinary PHP one: **one file per page at the top level**, with
the shared code in a folder beside them.

```
restaurant-menu/
│
├── index.php              the home page
├── menu.php               the menu, read from the database
├── order.php              choose dishes, pick a collection time
├── pay.php                M-Pesa, or pay on collection
├── order-status.php       track an order, cancel it, delete it
├── reserve.php            book a table
├── account.php            register and sign in
├── kitchen.php            the staff screen
├── payment-callback.php   where M-Pesa reports back
│
├── includes/              shared PHP — never opened directly
├── css/style.css          every style on the site
├── js/script.js           optional extras
├── images/                the 12 dish photos
├── database/              schema.sql, seed.sql
├── tools/                 command-line helper
│
├── docs/                  README, this file, the deploy guide,
│                          and the demo script
├── vercel.json            hosting configuration
└── favicon.svg + icons
```

### Why the pages sit at the top level

Opening `/menu.php` runs `menu.php`. **The URL is the filename.** There is no
router, no rewrite rules, no controller mapping — so there is nothing to
explain about how a request finds its code, and nothing to misconfigure.

Three practical consequences:

1. **It installs anywhere.** Copy the folder into XAMPP's `htdocs` and open
   `http://localhost/restaurant-menu/`. Every path in the HTML is relative
   (`css/style.css`, not `/css/style.css`), so the site works in a subfolder
   just as well as at a domain root.
2. **`php -S localhost:8000` is the whole dev setup.** The project root is the
   web root, so there is no document-root flag to get wrong.
3. **A missing page is a missing file**, which is exactly what a 404 should
   mean.

### Why the shared code sits in `includes/`

Every page begins with the same line:

```php
require __DIR__ . '/includes/setup.php';
```

`__DIR__` is the folder the running file is in, so the path is correct no
matter how the site is reached — domain root, subfolder, or dev server. Nothing
depends on the current working directory.

| File | Lines | What it is for |
|---|---:|---|
| `setup.php` | 32 | The first thing every page runs |
| `functions.php` | 340 | The database connection and the small helpers |
| `orders.php` | 311 | The life of an order |
| `auth.php` | 485 | Customer accounts, and the staff door |
| `payments.php` | 319 | Taking money |
| `messaging.php` | 1028 | Email and SMS |
| `session.php` | 91 | Where sessions are kept |
| `content.php` | 63 | The wording and opening hours, in one place |
| `header.php`, `footer.php` | 245 | The page frame every page shares |
| `config.php` | 68 | The database password. **Never committed.** |
| `config.example.php` | 72 | The template that *is* safe to share |

Grouping by **topic, not by type** is the choice worth defending. There is no
`models/`, `views/`, `controllers/`. There are twelve files, each one subject:
everything about taking money is in `payments.php`, everything about an order's
life is in `orders.php`. To change how cancelling works you open one file.

### The honest trade-off in this layout

With the pages at the top level, `includes/` is *inside* the web root, so
`/includes/auth.php` is a reachable URL. Two defences:

- `includes/.htaccess` denies it on Apache, which is what XAMPP runs.
- `vercel.json` returns 404 for `/includes/` when hosted.

Even without either, PHP executes those files rather than printing them, so no
password is exposed — but relying on that would be relying on a server being
configured correctly, which is not a defence.

The alternative is keeping a separate `public/` folder as the web root, which
puts `includes/` physically out of reach. That is marginally safer and the
usual choice for a production application. It also means the site cannot simply
be dropped into `htdocs`, and every path grows a `../`. For a project meant to
be read and run by someone else, the flat layout wins; for one handling real
customers, the `public/` layout would be the better call.

### What is deliberately *not* here

No `vendor/`, no `node_modules/`, no `composer.json`, no `package.json`, no
build output. Every file in this project is one I wrote and can explain. The
cost is visible in `messaging.php`, which contains an SMTP client written from
scratch — about 400 lines that one Composer package would have replaced.

---

## Step 2 — What happens when a page is requested

Every page starts with the same line:

```php
require __DIR__ . '/includes/setup.php';
```

`setup.php` does five things, in an order that matters:

1. **Sets the session cookie rules** — `httponly` so JavaScript cannot read it,
   `samesite=Lax` so another site cannot send it.
2. **Decides where sessions live** (Step 9) — this has to happen *before* the
   session starts, or the choice comes too late.
3. **Starts the session.**
4. **Loads the four topic files** — messaging, payments, auth, orders.
5. **Loads the wording** from `content.php` and sets the timezone, so every
   date on the site is Nairobi time rather than the server's.

After that line, the page has a database connection available, knows whether
anyone is signed in, and can start building HTML.

---

## Step 3 — Talking to the database

One function makes the connection, and it is the only place in the project that
does:

```php
function getPDO(): PDO
{
    static $pdo = null;          // one connection per request, not one per call
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: ($c['host'] ?? '');
        ...
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
```

Three decisions worth defending:

- **`static $pdo`** — the connection is made once and reused. A page that calls
  `getPDO()` twenty times still opens one connection.
- **`ERRMODE_EXCEPTION`** — a failed query throws instead of returning `false`.
  A mistake cannot be ignored by accident.
- **`ATTR_EMULATE_PREPARES => false`** — this is the important one. With
  emulation on, PHP builds the SQL string itself and only *pretends* to use a
  prepared statement. With it off, the placeholders are sent to MySQL
  separately from the values, so a value can never be read as SQL.

**The environment wins over the config file.** `getenv('DB_HOST') ?: $c['host']`
means the same code runs locally from `includes/config.php` and online from
environment variables, with nothing to change between them.

---

## Step 4 — The database

Thirteen tables. The ones that carry the work:

| Table | What one row is |
|---|---|
| `categories` | A section of the menu — Breakfast, Mains, Drinks |
| `menu_items` | One dish: name, description, price, photo |
| `restaurant_tables` | One table in the dining room: label, seats, area |
| `reservations` | One booking: a table, a date, a time |
| `orders` | One order: reference, customer, total, status |
| `order_items` | One line of an order: dish, quantity, **price at the time** |
| `users` | A customer account |
| `payments` | One attempt to take money |
| `order_events` | The audit trail — every status change, and who made it |
| `notifications` | Every email and SMS, whether or not it was sent |
| `login_attempts` | Failed sign-ins, for rate limiting |
| `sessions` | Sessions, when there is no shared disk (Step 9) |
| `order_access_codes` | The older way into an order, before accounts existed |

Two details in that table are worth more than they look.

**`order_items.unit_price` stores the price at the moment of ordering.** It is
not a link to today's price. If the restaurant puts pilau up tomorrow, last
week's order still totals what the customer actually agreed to pay. An order is
a record of an agreement, not a live calculation.

**`order_events` is never updated or deleted, only added to.** Every move an
order makes writes a row saying what changed and who changed it. That is how
the kitchen screen can show the history of an order, and how a disputed
cancellation can be answered.

---

## Step 5 — A page that reads: the menu

`menu.php` is the simplest full example — a query, a loop, and a safe print.

```php
$rows = getPDO()->query(
    'SELECT c.name AS category_name, i.id, i.name, i.description, i.price,
            i.image, i.image_alt
       FROM categories c
       JOIN menu_items i ON i.category_id = c.id
      WHERE i.is_available = 1
      ORDER BY c.display_order, i.name'
)->fetchAll();
```

One query gets the dishes **and** their category names. Looping over the
categories and querying for each one's dishes would work too, and would be
wrong: that is N+1 queries where one will do.

The rows are then grouped in PHP and printed:

```php
<h3><?= e($row['name']) ?></h3>
<p>KSh <?= number_format($row['price'], 0) ?></p>
```

`e()` is the output guard, used on **every** value that came from the database
or a form:

```php
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
```

If a dish were named `<script>alert(1)</script>`, `e()` makes the browser print
those characters instead of running them. Prepared statements stop bad input
going *in*; `e()` stops it doing harm coming *out*. Both are needed — neither
one covers the other.

**If the database is down, the page still loads.** The query sits in a
`try/catch`; on failure the page shows "The menu is unavailable right now"
instead of a blank screen or a stack trace.

---

## Step 6 — A page that writes: placing an order

This is the most involved path in the project, and the one worth walking
through slowly.

**1. The form is checked before anything is written.** Quantities are read with
`buildOrderLines()`, which looks every dish up in the database rather than
trusting the form:

```php
$marks = implode(',', array_fill(0, count($wanted), '?'));
$stmt  = getPDO()->prepare(
    "SELECT id, name, price, is_available FROM menu_items WHERE id IN ($marks)"
);
$stmt->execute(array_keys($wanted));
```

The `?` placeholders are built from `count()`, never from the submitted values.
**The price comes from the database, never from the form** — otherwise anyone
could edit the page and buy nyama choma for one shilling.

**2. A reference is generated**, like `MD-7K3QB2`, from an alphabet with no
`I`, `O`, `0` or `1` in it, so it can be read aloud over a phone without
confusion. The code checks the database and tries again if it collides.

**3. Everything is written in one transaction.**

```php
$pdo->beginTransaction();
try {
    // insert the order, then each of its lines
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
}
```

Without this, a failure halfway through would leave an order in the database
with only half its dishes on it. The transaction means the order arrives whole
or not at all.

**4. The customer is redirected**, not shown the page directly. After a
successful POST the browser is sent to a fresh URL, so pressing reload does not
place the order a second time.

---

## Step 7 — The kitchen screen, and why orders cannot skip a step

`kitchen.php` shows the live board. The stages an order may move through are
defined in exactly one place:

```php
function order_next_statuses(string $current): array
{
    return match ($current) {
        'new'       => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        default     => [],      // completed and cancelled are final
    };
}
```

The screen draws its buttons from this function, and `move_order()` checks
against the same function before writing. So the rule is enforced once and
cannot drift: an order can never jump from `new` to `completed`, and nothing
can come back out of `cancelled`.

**Two staff pressing buttons at the same moment.** The order row is locked for
the length of the move:

```sql
SELECT * FROM orders WHERE reference = ? FOR UPDATE
```

The second request waits for the first to finish, then re-reads the status and
finds the move no longer allowed. Without the lock, both would read `new`, both
would think they were right, and one change would quietly overwrite the other.

**The board refreshes itself without JavaScript**, using
`<meta http-equiv="refresh" content="45">`. A tablet on a kitchen shelf stays
current even with scripting off.

---

## Step 8 — Booking a table, and the double-booking problem

The obvious way to prevent two bookings on one table is to check first:

```php
// WRONG
if (!already_booked($table, $date, $time)) {
    insert_reservation(...);
}
```

Two people clicking at the same instant both pass the check, and both bookings
are written. The check and the write are not one action.

So the rule lives in the database instead:

```sql
UNIQUE KEY uniq_table_slot (table_id, reservation_date, reservation_time)
```

Now the second `INSERT` cannot succeed — MySQL refuses it with SQLSTATE 23000.
The PHP catches that specific code and turns it into a readable message:

```php
// 23000 is the database refusing a duplicate.
if ($ex instanceof PDOException && $ex->getCode() === '23000') {
    $errors['table_id'] = "Table {$table['label']} has just been booked. Please choose another.";
}
```

The friendly check still runs, because it gives a better message most of the
time. But the thing that actually *guarantees* correctness is the constraint,
because only the database can make checking and writing a single step.

---

## Step 9 — Sessions, and why they moved into the database

PHP keeps sessions as files in a temp directory. That is fine on one server.

On a serverless host, each request may run in a brand-new container with its
own temp directory, so the next request cannot read what the last one wrote.
On this site that would not merely break "stay signed in" — **every form
carries a CSRF token kept in the session**, so every submission would come back
"your session expired". The site would look perfect and do nothing.

`includes/session.php` registers a handler that keeps sessions in the
`sessions` table, which every container shares. `$_SESSION` is used exactly as
before; only the shelf changed.

It switches on by itself:

```php
if ($driver === 'database') { return true; }
if ($driver === 'files')    { return false; }
return getenv('VERCEL') !== false;   // Vercel sets this on every deployment
```

**How this was tested.** Two PHP processes were started with different
`session.save_path` values — the closest stand-in for two containers:

| | files | database |
|---|---|---|
| Second container sees the signed-in session | **no** | yes |
| CSRF token minted by A accepted by B | — | **yes** |

---

## Step 10 — What protects the site

| Attack | What stops it |
|---|---|
| SQL injection | Prepared statements everywhere, with `EMULATE_PREPARES` off |
| XSS | `e()` on every printed value |
| Cross-site request forgery | A token in every form, compared with `hash_equals()` |
| Reading someone else's order | `may_view_order()` asks the database who owns it |
| Password theft from the database | `password_hash()`, so there are no passwords to steal |
| Brute-forcing the staff password | `login_attempts` locks out after 6 tries for 15 minutes |
| Double booking | A `UNIQUE` constraint in the database |
| Two staff colliding | `SELECT … FOR UPDATE` row locks |
| Charging twice for one payment | A `UNIQUE` provider reference, so a repeat is ignored |

Two of those deserve a sentence each.

**`hash_equals()` instead of `===`** for the CSRF check. A normal string
comparison stops at the first wrong character, so how long it takes reveals how
much of the token was right. `hash_equals()` always takes the same time.

**Deleting a paid order is refused outright.** The order row is the only record
that money changed hands, so `delete_order()` checks both the order and the
`payments` table and refuses. A cancelled *unpaid* order can be deleted; a paid
one must be refunded and kept.

---

## Step 11 — The site works without JavaScript

Every page is built from ordinary forms and links. `js/script.js` only adds
comfort: a character counter, a live total, the sliding menu.

Turn JavaScript off and you can still read the menu, order food, pay, track the
order, book a table, and run the kitchen. The kitchen board still refreshes,
because it uses `<meta refresh>` rather than polling.

This is a design choice, not an accident. The JavaScript is where the site is
*nicer*; the PHP is where the site *works*.

---

## Step 12 — The numbers

| | |
|---|---:|
| Pages | 9 |
| Shared PHP files | 12 |
| Functions in `includes/` | 78, every one of them called |
| Lines of PHP | ~7,000 |
| Lines of CSS | 5,784 |
| Database tables | 13 |
| Dishes seeded | 12 |
| Tables in the dining room | 19 |
| Frameworks and build steps | 0 |

---

## Likely questions

**Why no framework?** The brief was to show that I can build this, not that I
can configure something that builds it. Every decision in the project is one I
made and can defend. A framework would have made the same site with most of the
interesting parts hidden inside somebody else's code.

**Why is `messaging.php` the longest file?** It contains an SMTP client written
from scratch — opening the socket, the handshake, AUTH, the MIME body. A
project allowed to use Composer would be one `composer require` line instead.

**What would you do next?** Split `messaging.php`, since an SMTP client and the
"what should we send" rules are two different jobs. Add a proper test suite —
testing so far has been by hand plus checking HTML output byte-for-byte after
refactors. And move the dish photos onto a CDN, because they are the slowest
thing on the page.

**What went wrong along the way?** The one worth telling: dish photos were
suddenly missing, and the cause was that `seed.sql` had a semicolon *inside a
comment*. The import split the file on `;`, cut that comment in half, and
silently skipped every statement after it — which happened to include all
twelve photo updates and the dining room's tables. The lesson was that a
"successful" import that stops partway is worse than one that fails loudly.
