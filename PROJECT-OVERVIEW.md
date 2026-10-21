# Maridadi Eat — how this project is built

A walkthrough of the whole system: the files, the front end, the back end,
the database, and how a request travels through all of them.

---

## 1. What it is

A restaurant website where customers read a menu, order food, pay, track the
order, and book a table — and where staff run the kitchen.

| | |
|---|---|
| Language | PHP 8.4 |
| Database | MariaDB / MySQL (InnoDB, `utf8mb4`) |
| Front end | One CSS file, one JS file. No framework. |
| Dependencies | **None.** No composer, no npm, no build step. |

**Why no framework.** Every file can be opened and read top to bottom, and
the whole thing can be copied onto cheap shared hosting and run. Nothing has
to be compiled first.

---

## 2. The folders

```
restaurant-menu/
│
├── public/      ← THE WEB ROOT. The only folder a browser can reach.
├── src/         ← The engine. Never served.
├── database/    ← The SQL that builds the tables.
├── tools/       ← Command-line helpers.
├── storage/     ← Files written at runtime (gitignored).
└── assets/      ← Full-size photo originals, kept out of the web root.
```

**This split is the single most important structural decision.** The web
server is pointed at `public/`, so a browser can only ever request files in
there. `src/` holds the database password and the engine — a browser cannot
reach them *at all*, even if the server is misconfigured, because they are
not under the served folder.

---

## 3. The front end — `public/`

Nine pages. Each is a PHP file that produces HTML.

| Page | What it does |
|---|---|
| `index.php` | Home: hero, about, map, opening hours |
| `menu.php` | The menu, grouped by course |
| `account.php` | Sign in / register / your account |
| `order.php` | Ordering — needs an account |
| `pay.php` | Paying for an order |
| `order-status.php` | "My orders" — the list, or one in full |
| `reserve.php` | Booking a table |
| `kitchen.php` | **The staff screen** — orders and bookings |
| `payment-callback.php` | Where M-Pesa posts its result (no HTML) |

Plus `style.css` (every style), `script.js` (every script) and `images/`.

### Every page has the same shape

```php
require __DIR__ . '/../src/setup.php';   // 1. load the engine

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2. validate, write to the database, redirect
}

// 3. read whatever the page needs to show

require __DIR__ . '/../src/header.php';  // 4. the page top
?>
   ... this page's own HTML ...
<?php require __DIR__ . '/../src/footer.php';
```

### The front end works with JavaScript switched off

This is a deliberate rule, not an accident. `script.js` only *improves*
things that already work:

- the quantity steppers wrap a plain number input;
- the running total updates as you click, **but PHP recalculates it from
  scratch on submit and that figure is the one stored**;
- the password reveal button is `hidden` in the HTML and the script removes
  the attribute — so with no JavaScript there is no dead button;
- the kitchen board refreshes with `<meta refresh>`, not a script.

Anything the browser calculates is treated as a hint. The server never
trusts it.

---

## 4. The back end — `src/`

Eleven files. The rule for grouping them: **if you would always open two
files together, they should be one file.**

| File | Holds |
|---|---|
| `setup.php` | The first line of every page. Starts the session, loads the five files below. |
| `config.php` | **Your settings** — database password, keys. Gitignored. |
| `config.example.php` | The committed template, with every secret blank. |
| `content.php` | Everything the restaurant owns: name, address, hours, slots, delivery rules. No PHP to touch. |
| `functions.php` | The toolbox: `getPDO()`, `e()`, CSRF tokens, the logo, breadcrumbs. |
| `messaging.php` | Settings, sending email (SMTP), sending SMS, and what gets sent when. |
| `payments.php` | Starting, settling and failing a payment. |
| `auth.php` | Customer accounts **and** the staff door, sharing one rate limiter. |
| `orders.php` | The life of an order, and the kitchen's queries. |
| `header.php` / `footer.php` | The page shell. |

`setup.php` is the only file that `require`s anything, so there is one place
to look to know what a page has available to it.

### Three "driver" files

`messaging.php` and `payments.php` share a shape: one function the rest of
the site calls, and a `switch` inside choosing between a real provider and a
local stand-in.

| Setting | Options |
|---|---|
| `mail.driver` | `log` (write to a file) · `smtp` (really send) · `mail` |
| `sms.driver` | `log` · `africastalking` |
| `payments.driver` | `manual` (confirm at the till) · `mpesa` (Daraja STK push) |

Nothing outside those files knows which one is running. **That is what lets
the whole site be developed and demonstrated with no accounts, no API keys
and no internet** — and it makes going live a configuration change rather
than a code change.

---

## 5. The database

Thirteen tables. Every relationship is a real foreign key, so the database
refuses inconsistent data even if the PHP above it has a bug.

```
  users ──< orders ──< order_items >── menu_items >── categories
              │
              ├──< payments
              ├──< notifications
              ├──< order_events
              └──< order_access_codes

  restaurant_tables ──< reservations

  login_attempts    sessions        (stand alone — rate limiting, session data)
```

| Table | What it is |
|---|---|
| `categories` | Breakfast, Mains, Drinks |
| `menu_items` | The dishes: name, price, photo, available or not |
| `users` | A customer account. Ordering requires one. |
| `orders` | Who, when, collection or delivery, and the money |
| `order_items` | The dishes on an order |
| `payments` | Every **attempt** to pay, successful or not |
| `order_events` | Every status change an order has been through |
| `notifications` | An outbox: every email and SMS we tried to send |
| `order_access_codes` | One-time codes (legacy orders without an account) |
| `restaurant_tables` | The physical tables in the room |
| `reservations` | A booking: who, when, which table |
| `login_attempts` | Sign-in attempts, for rate limiting both doors |
| `sessions` | Session data, when the host has no shared disk |

### Five decisions worth explaining

**1. `order_items.unit_price` is copied, not looked up.**
When the kitchen changes a price later, old orders must still show what the
customer actually paid. The dish's *current* price lives in `menu_items`;
what was *charged* lives in `order_items`.

**2. One unique key stops double-booking.**

```sql
UNIQUE KEY uniq_table_slot (table_id, reservation_date, reservation_time)
```

Not the PHP check — the PHP check is a courtesy that produces a friendly
message. **The unique key is the guarantee**, and it holds even when two
people press the button in the same millisecond: the second `INSERT` simply
fails, and the page turns that into "that table has just been booked".

**3. `status` and `payment_status` are separate columns.**
How far the kitchen has got and whether the money arrived are different
questions and do not move together. A paid order can still be waiting to be
cooked; a cash order is handed over long before it is paid.

**4. `payments.provider_ref` is `UNIQUE`.**
Payment providers retry their callback until they get a `200`, so the same
receipt *will* arrive twice. The unique key makes crediting one payment
twice impossible **at the database level**, rather than something the PHP
has to remember to check.

**5. `order_access_codes` stores a hash, never the code.**
A copied database then hands over useless hashes rather than live codes.

---

## 6. How the front end and the database are connected

One function, in `functions.php`:

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
```

- **PDO**, so the same code runs on MySQL and MariaDB.
- **Exceptions on error**, so a failure is loud rather than a silent `false`.
- **`EMULATE_PREPARES => false`** — this one matters. It forces *real*
  server-side prepared statements, so values are never pasted into SQL text.

Every value reaches the database as a placeholder:

```php
$stmt = $pdo->prepare('SELECT * FROM orders WHERE reference = ?');
$stmt->execute([$reference]);
```

**That is the SQL-injection defence**, and it is used everywhere without
exception.

The connection is opened once per request and reused. The database's clock
is pinned to the restaurant's timezone (`Africa/Nairobi`) so PHP and MySQL
agree about what "now" means.

---

## 7. A request, start to finish

Placing an order:

```
  Browser                 order.php              src/*.php            MySQL
     │
     │  POST qty, name, …     │
     ├───────────────────────>│
     │                        │ csrf_valid()?
     │                        │ buildOrderLines()  ──prices──────────>│
     │                        │<──────────────────────────────────────┤
     │                        │ validate: dishes, name, email,
     │                        │ phone, address, delivery minimum,
     │                        │ the slot, the lead time
     │                        │
     │                        │ BEGIN ───────────────────────────────>│
     │                        │   INSERT orders                       │
     │                        │   INSERT order_items (one per dish)   │
     │                        │   INSERT order_events                 │
     │                        │ COMMIT ──────────────────────────────>│
     │                        │
     │                        │ notify_order_placed()  → email + SMS
     │  302 → pay.php         │
     │<───────────────────────┤
```

Two things in that diagram carry real weight:

- **Prices come from the database, never from the form.** A tampered form
  cannot change what anything costs.
- **The inserts are one transaction.** An order can never exist without its
  dishes; any failure rolls the whole thing back.

And every successful POST ends in a **redirect**, so refreshing the page
cannot place the order twice.

---

## 8. The kitchen screen

`kitchen.php` is where an order actually moves. Without it, every order
would sit on "received" for ever.

```
  new  ──Start cooking──►  preparing  ──Mark ready──►  ready
                                                         │
                                                  Mark collected
                                                         ▼
                                                     completed
  new / preparing / ready  ──Cancel──►  cancelled   (then deletable)
```

- **Forwards only, plus cancel.** No undo — a mis-tap must not rewrite
  history.
- The move is re-checked **inside a `FOR UPDATE` row lock**, against the
  database rather than against whatever the screen was showing. Two cooks on
  two tablets will both have a stale screen eventually.
- The status change and its history row are written in **one transaction**.
- Marking an order **ready** texts and emails the customer. Cancelling does
  too. "Started cooking" sends nothing — that is noise, and every SMS costs
  money.
- A second tab shows **bookings**, grouped by day, soonest first.

### How the two screens stay in sync

They were always one source of truth — one table, one column, no copies to
reconcile. What was missing was that the customer had to reload to notice:

| | |
|---|---|
| Kitchen board | reloads every **45s** |
| Customer's tracking page | reloads every **30s**, *only while the order is live* |

Both use `<meta http-equiv="refresh">` rather than polling from script,
because a kitchen tablet on a shelf and a phone with the tab in the
background both have to keep working.

---

## 9. What protects the data

| Threat | Defence |
|---|---|
| SQL injection | Real prepared statements everywhere; `EMULATE_PREPARES => false` |
| Cross-site scripting | Everything printed goes through `e()` (`htmlspecialchars`) |
| Cross-site request forgery | Every form carries a token, checked with `hash_equals()` |
| Price tampering | Prices read from the database, never the request |
| Double-booking | A `UNIQUE` key, not a PHP check |
| Double-charging | Row lock + early return + `UNIQUE` receipt number |
| Duplicate submission | Every successful POST redirects |
| Password theft | Only bcrypt hashes stored — never a readable password |
| Brute force | 8 tries per account, 6 for staff, then a 15-minute lockout, counted **in the database** by IP *and* by email |
| Reading someone else's order | Ownership is `orders.user_id`, checked against the database |
| Session hijacking | `httponly`, `samesite=Lax`, `secure` on HTTPS, new id on sign-in |
| Leaked credentials | `config.php` is outside the web root and gitignored |

**One worked example — the login tells you nothing.** A wrong password and an
unknown email give the *same* message, and `password_verify()` runs even when
there is no such account (against a throwaway hash) so a missing address does
not answer measurably faster. Both would otherwise reveal which email
addresses have accounts here.

---

## 10. Running it

### Locally

```bash
# 1. the database
mariadb -u root -p -e "CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4;"
mariadb -u root -p restaurant_menu < database/schema.sql
mariadb -u root -p restaurant_menu < database/seed.sql

# 2. the settings
cp src/config.example.php src/config.php     # then edit it

# 3. the web server
php -S localhost:8000 -t public
```

Open <http://localhost:8000>.

### The three SQL files

| File | When |
|---|---|
| `schema.sql` | Builds every table from nothing. Safe to re-run. |
| `seed.sql` | The menu and the tables in the room. |
| `upgrade.sql` | Brings an **older** database up to date. Idempotent — run it with `--force`. |

### On Vercel

`vercel.json` and `.vercelignore` are included and the code-level blockers
are fixed. Three things to know:

1. **PHP on Vercel is a community runtime** (`vercel-php`), not an official one.
2. **The database must be hosted elsewhere** — PlanetScale, Railway, Aiven.
   Vercel provides no MySQL.
3. **The filesystem is read-only.** The `log` mail driver cannot write its
   `.eml` files, so it records the message in `notifications` and says so.
   That is the only place the site writes to disk.

Settings come from environment variables, because `config.php` is gitignored
and will not be deployed:

```
DB_HOST   DB_NAME   DB_USER   DB_PASS        ← required
STAFF_PASSWORD_HASH   BASE_URL
MAIL_DRIVER   SMTP_HOST   SMTP_USERNAME   SMTP_PASSWORD
```

#### Sessions had to move

This was the real blocker, and it was not about staying signed in.

PHP keeps sessions as files in a temp directory. Serverless containers do
not share one, so the next request cannot read what the last wrote. **Every
form on this site carries a CSRF token held in the session** — so ordering,
booking, paying and the kitchen's buttons would all have come back "your
session expired". The site would have looked fine and done nothing.

`src/session.php` registers a `session_set_save_handler` that keeps sessions
in the `sessions` table instead. Same `$_SESSION`, different shelf. It turns
on automatically when `VERCEL` is set, or with `SESSION_DRIVER=database`,
and falls back to files if the database cannot be reached.

Proved rather than assumed, by running two PHP processes with **different**
`session.save_path` values — the closest local stand-in for two containers:

| | files | database |
|---|---|---|
| Signed in on container B with A's cookie | **no** | yes |
| CSRF token minted by A, accepted by B | — | **yes, 302** |
| B sees the account's orders | no | yes |

---

## 11. What is deliberately not built

Worth saying out loud, because each was a decision rather than an oversight.

**No card form.** Taking a card number on our own server pulls the whole
site into PCI scope and means storing and eventually leaking the most
sensitive field a customer has. The right way is a redirect to the gateway's
own hosted page, so the number is typed on *their* domain. That slots in
beside M-Pesa as another method.

**No password reset.** A lost password currently means a new account.
Everything needed exists (the mailer, the outbox, the hashed-token pattern),
so it is assembly rather than design — but it is not built.

**No email verification.** Registration trusts what was typed.

**No staff accounts.** One shared kitchen password, so `order_events.actor`
can say *when* but not *who*.

---

## 12. The numbers

| | |
|---|---|
| PHP files | 21 (9 pages, 11 engine, 1 tool) |
| Database tables | 13 |
| Lines of PHP | ~6,700 |
| Lines of CSS | ~5,500 (one file) |
| Lines of JS | ~650 (one file, all optional) |
| External dependencies | **0** |
