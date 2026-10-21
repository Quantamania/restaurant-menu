# Maridadi Eat — how the site is built

This document describes the whole site: the files, the database, how a request
is served, how money is taken, how email and SMS are sent, how each visitor
journey works, and what protects the data. It also records what was tested,
and the things that still need doing before the site handles real customers.

Written 21 October 2026, against the code as it stands. Sections 4a, and the
payment and messaging parts of 5–8, were added with the payment screen.

---

## 1. The shape of it

A plain PHP site. No framework, no build step, no package manager. You can open
any file and read it top to bottom.

| | |
|---|---|
| Language | PHP 8.4 |
| Database | MariaDB / MySQL, InnoDB, `utf8mb4` |
| Database access | PDO with prepared statements |
| Front end | One stylesheet, one script file, no libraries |
| Email | hand-written SMTP client, no dependencies |
| SMS | Africa's Talking |
| Payments | M-Pesa (Daraja STK push), or confirmed at the till |
| Fonts | Google Fonts (Anton, Poppins, Roboto) |
| Map | OpenStreetMap embed — no key, no account |

Everything the browser can reach lives in `public/`. Everything else —
the database credentials, the page templates, the shared functions — sits
outside it in `src/`, so a misconfigured server cannot serve them as text.

```
restaurant-menu/
├── public/                 the web root — point the server here
│   ├── index.php           home
│   ├── menu.php            the menu
│   ├── order.php           ordering — dishes, then details
│   ├── pay.php             paying for an order
│   ├── payment-callback.php  where M-Pesa posts the result
│   ├── account.php         sign in / register
│   ├── order-status.php    "my orders" — the list, or one in full
│   ├── reserve.php         booking a table
│   ├── kitchen.php         THE STAFF SCREEN — password protected
│   ├── style.css
│   ├── script.js
│   ├── favicon.svg, favicon.ico, apple-touch-icon.png, icon-192/512.png
│   └── images/             dish photos, served at 640×640
├── src/                    never served
│   ├── config.php          database credentials (gitignored)
│   ├── config.sample.php   the template to copy
│   ├── secrets.php         mail / SMS / payment keys (gitignored)
│   ├── secrets.example.php the template to copy, with the blanks empty
│   ├── setup.php           the bootstrap every page runs first
│   ├── functions.php       the shared toolbox
│   ├── mail.php            secrets(), send_email(), and an SMTP client
│   ├── sms.php             send_sms(), normalise_phone()
│   ├── notify.php          what gets sent and when; the one-time codes
│   ├── payments.php        payment state machine, M-Pesa STK push
│   ├── accounts.php        customer accounts, and the shared rate limiter
│   ├── staff.php           staff sign-in, and the status state machine
│   ├── content.php         the restaurant's own text and rules
│   ├── header.php          the top of every page
│   └── footer.php          the bottom of every page
├── database/
│   ├── schema.sql          the tables
│   ├── seed.sql            draft menu, tables, photos
│   └── upgrade.sql         brings an older database up to date (idempotent)
├── tools/
│   └── staff-password.php  makes the kitchen password hash (command line)
├── storage/                gitignored, written at runtime
│   └── mail/               .eml files from the 'log' mail driver
├── assets/originals/       full-size photos, kept out of the web root
└── README.md               setup instructions
```

### Why there are three "driver" files

`mail.php`, `sms.php` and `payments.php` all have the same shape: one
function the rest of the site calls, and a `switch` inside it choosing
between a real provider and a local stand-in. Nothing outside those files
knows which one is running.

That is what lets the whole site be developed and tested with no accounts,
no API keys and no internet — and it is also what makes going live a
configuration change rather than a code change.

---

## 2. What happens when a page is requested

Every page follows the same four steps.

```
  browser
     │
     ▼
  public/<page>.php
     │  1. require ../src/setup.php
     │       ├── session_set_cookie_params(httponly, samesite, secure)
     │       ├── session_start()
     │       ├── require functions.php         the toolbox
     │       ├── require mail.php / sms.php    sending
     │       ├── require notify.php            what to send, and the codes
     │       ├── require payments.php          taking money
     │       ├── $site = require content.php   the restaurant's text and rules
     │       └── date_default_timezone_set($site['timezone'])
     │
     │  2. if this is a POST, validate it and write to the database
     │       └── on success: redirect, then exit
     │
     │  3. read whatever the page needs to show
     │
     │  4. require ../src/header.php
     │     ... the page's own HTML ...
     │     require ../src/footer.php
     ▼
  HTML
```

Two details in that sequence matter more than they look.

**The timezone is set from `content.php`, not left to the server.** The
restaurant is in `Africa/Nairobi`; a server is usually on UTC. Without this
line, "has that collection slot already passed?" gets answered against the
wrong clock and is three hours out. The database connection is pinned to the
same offset in `getPDO()`, so PHP and the database agree on what "now" is.

**A successful POST redirects before printing anything.** That is what stops a
refresh from placing the same order twice.

---

## 3. The database

Twelve tables. Every relationship is a real foreign key, so the database
refuses inconsistent data even if the PHP above it has a bug.

```
  users ──< orders
              │
  categories ──< menu_items ──< order_items >──┤
                                               ├──< payments
                                               ├──< notifications
                                               ├──< order_access_codes
                                               └──< order_events

  restaurant_tables ──< reservations

  login_attempts               (stands alone — rate limiting only)
```

### categories
The courses: Breakfast, Mains, Drinks. `display_order` controls the order they
appear in, everywhere.

### menu_items
A dish. `price` is `DECIMAL(8,2)` — never a float, because money must not drift.
`is_available` is the switch that hides a dish from the site without deleting
it. `image` is a filename inside `public/images/`; `image_alt` describes the
photo for a screen reader.

Deleting a category deletes its dishes (`ON DELETE CASCADE`).

### orders
One row per order. Holds the customer's details, whether it is collection or
delivery, when they want it, the three money columns and the kitchen status.

- `reference` is the short code the customer quotes. **`UNIQUE`.** New ones
  are `MD-` (Maridadi); orders taken before the rename keep their `ZW-` and
  still resolve, because a reference is looked up as an exact string.
- `status` is `new → preparing → ready → completed`, or `cancelled`.
- `payment_status` is `unpaid → pending → paid`, or `failed`.
- Indexes: `idx_kitchen (order_date, order_time)` for "what is due next",
  `idx_status (status, order_date)` for the kitchen's own list, and
  `idx_owed (payment_status, order_date)` for the till's.

**`status` and `payment_status` are deliberately separate columns.** How far
the kitchen has got and whether the money has arrived are not the same
question and they do not move together: a paid order can still be waiting to
be cooked, and a cash order is cooked and handed over long before it is paid.
Folding them into one column forces nonsense states like "preparing, or
paid?".

### order_items
One row per dish in an order.

`unit_price` is **copied from the dish at the moment of ordering**. This is
deliberate and it matters: when the kitchen changes a price later, old orders
must still show what the customer actually paid. The dish's current price lives
in `menu_items`; what was charged lives here.

Deleting an order deletes its lines (`ON DELETE CASCADE`). Deleting a *dish* is
not cascaded — the foreign key refuses, which protects order history.

### restaurant_tables
A physical table: `label`, `seats`, `area` (Window, Terrace, Garden…), and
`is_active`.

### reservations
A booking. The important piece:

```sql
UNIQUE KEY uniq_table_slot (table_id, reservation_date, reservation_time)
```

**That one line is what makes double-booking impossible.** Not the PHP check —
the PHP check is a courtesy that produces a friendly message. The unique key is
the guarantee, and it holds even when two people press the button in the same
millisecond. (Proved in §7.)

If a table is removed, its bookings survive with `table_id` set to `NULL`
(`ON DELETE SET NULL`) rather than vanishing.

### payments
One row per **attempt** to pay, not one per order.

A payment is a conversation with somebody else's system. It gets retried, it
times out, it fails and is tried again on another number. Keeping one row per
attempt means a failed try is history you can read back when a customer says
"but it took my money" — instead of a value that was overwritten.

- `status` is `pending → paid`, or `failed` / `cancelled`.
- `provider_ref` is M-Pesa's receipt number, and it is **`UNIQUE`**.

That unique key is the same idea as `uniq_table_slot`. Payment providers
retry their callback until they get a `200`, so the same receipt *will*
arrive more than once. The key makes crediting one payment twice impossible
at the database level, rather than something the PHP has to remember.

### notifications
An outbox: every email and SMS the site has tried to send, with the
provider's own error text kept verbatim.

Email and SMS leave the building and fail for reasons nobody here controls —
a mistyped address, a provider outage, no credit on the SMS account. If the
only record is the customer saying "nothing arrived", there is nothing to
look at. One row per message turns that into a question that can be answered:

```sql
SELECT purpose, recipient, error FROM notifications
 WHERE status = 'failed' ORDER BY created_at DESC;
```

`order_id` is `ON DELETE SET NULL`, so purging an old order leaves the
sending history intact.

### order_access_codes
The six-digit codes that unlock "track my order".

Three rules are enforced by the columns rather than left to the PHP:

| Column | What it prevents |
|---|---|
| `code_hash` (sha256, never the code) | a copied database handing over live codes |
| `expires_at` | a code from last week still working |
| `attempts` | guessing six digits at unlimited tries |

### order_events
Every status change an order has been through.

`orders.status` says where an order is *now*. This says how it got there, when,
and who moved it. A kitchen runs on "when did that go out?" and "who cancelled
this?", and neither can be answered by one column that gets overwritten. It is
also the honest record if a customer disputes a cancellation.

`actor` is `customer` for the opening event, `kitchen` for a staff action, and
`system` for the rows backfilled onto orders that predate the screen. When
there are named staff accounts it becomes their name, without the table
changing shape.

### users
A customer account. Ordering requires one.

That is friction before the first order, and what it buys is that "where is
my food?" becomes "sign in" — no reference code to keep and no one-time
password sent to a phone that might be flat.

`email` is the identity, so it is **`UNIQUE`**. Letting the database decide
that closes the gap a "SELECT then INSERT" leaves open, where two people
registering at the same moment could both find the address free. `register_account()`
catches `SQLSTATE 23000` and turns it into "there is already an account with
that email" — the same pattern as `uniq_table_slot` on reservations.

`password_hash` holds `password_hash()` output: bcrypt, salt and cost inside
the value. There is nowhere in this database that a readable password exists.

### login_attempts
Sign-in attempts, for rate limiting. **One table for both doors** — `scope`
is `'staff'` or `'customer'`. They need identical defences, so they share an
implementation rather than having two that can drift apart.

`ip` is `VARBINARY(16)`, which holds an IPv4 or an IPv6 address in the packed
form `inet_pton()` returns. `identity` is the email that was tried, and is
NULL for the staff door, which has no username.

Counting **two ways** matters: by IP, which slows one machine working through
a list of passwords; and by email, which slows a list of machines all
attacking one account. Either hitting the cap locks.

Rows older than a day are deleted on every write, so the table cannot grow
without bound — this project has no cron.

---

## 4. The toolbox — `src/functions.php`

| Function | What it does |
|---|---|
| `getPDO()` | Opens the connection once and reuses it. Errors throw exceptions, results come back as associative arrays, emulated prepares are off (so placeholders are real). Pins the session timezone. |
| `e($s)` | HTML-escaping. **Every** piece of data printed into a page goes through it. |
| `csrf_token()` / `csrf_valid($t)` | Issues a per-session token and checks it with `hash_equals`. |
| `getTablesWithAvailability($date,$time)` | Every active table with a `taken` flag for that slot. |
| `getTable($id)` | One table, or `null`. |
| `getOrderableMenu()` | Available dishes grouped by course. |
| `buildOrderLines($qty,$max)` | **The money function.** See below. |
| `makeOrderReference()` | A short unique code. |
| `getOrderByReference($ref)` | An order with its lines, for the status page. |
| `wordmark($name,$class)` | The logo wordmark, with the first E drawn as three bars. |

### `buildOrderLines()` — why prices are read from the database

The browser posts *quantities only*. It never posts a price. `buildOrderLines()`
takes those quantities, looks every dish up in the database, and builds the
order from the prices it finds there.

That is the difference between a shop and a shop that can be robbed. If the
price came from the form, anyone could edit it before posting.

It also:

- clamps each quantity to `max_per_dish` (20) — a posted `999` becomes `20`;
- drops anything that is not a real, available dish;
- returns a `dropped` list naming any dish that went unavailable mid-session,
  so the order can be **refused** rather than silently charging less. This was a
  real bug: an order once charged 650 instead of 2,450 because a dish sold out
  between loading the page and submitting it.

---

### Two shared pieces of chrome

`brand_lockup()` and `breadcrumbs()` live in `src/functions.php` because more
than one document needs them and `src/header.php` is not one of those
documents.

**`brand_lockup($name, $class, $idSuffix)`** returns the bitten box mark plus
the wordmark. It was lifted out of `header.php` when the kitchen screen
needed the same logo: the kitchen is its own document (it has no site
navigation, carries a `<meta refresh>` and must not be indexed), so it cannot
just include the shared header. Two copies of that SVG would have drifted
apart.

`$idSuffix` exists because an SVG `mask` is referenced by id, and an id has to
be unique in a document. A second lockup on one page pointing at a duplicate
id renders against whichever the browser found first — so the second logo
would quietly lose its bite. The kitchen passes `bar` and `gate`.

Two things have to be undone to use it outside the sidebar, and the
`.brand--kitchen` modifier does both: `.brand` carries vertical margins meant
for the rail, and **`.brand__word` is `display: none` everywhere except the
landing page**, with `.wordmark` coloured `#e8e8e8` for dark panels. Dropped
into a white toolbar unchanged, the wordmark is both hidden and — once shown —
1.2:1 against the background.

**`breadcrumbs($trail)`** takes `[[label, href], …]` from the home page
inwards and returns a `<nav aria-label="Breadcrumb">` wrapping an ordered
list. Three decisions worth keeping:

- the **last entry is rendered as text, not a link**, with
  `aria-current="page"` — a link to the page you are already on is noise, and
  a screen reader announces it as somewhere to go;
- an **ordered** list, because the order is the meaning;
- the `/` separators are `aria-hidden`, or every step is read out as "slash".

On the inner pages the trail is indented 60px above 961px, because the rail's
pull-in button is `position: fixed` at the very top of the content column —
exactly where the first crumb starts. One number covers both rail states:
open, the shell begins at 104 and the button spans 116–150; closed, the shell
begins at 0 and the button spans 12–46. Either way the trail needs 46px plus
a gap, measured at 14px in both.

A single-entry trail returns `''`, which is why the home page sets no
`$crumbs` and gets nothing. Each page sets `$crumbs` before including
`header.php`; the kitchen calls `breadcrumbs()` directly.

| Page | Trail |
|---|---|
| `index.php` | — (the root) |
| `menu.php` | Home / Menu |
| `order.php` | Home / Menu / Order food |
| `pay.php` | Home / Order food / Payment |
| `order-status.php` | Home / Track your order |
| `reserve.php` | Home / Reserve a table |
| `account.php` | Home / Your account |
| `kitchen.php` | Home / Kitchen |

---

## 4a. Money, email and SMS

### How paying is arranged

Payment is its own page with its own URL (`pay.php?ref=…`), not a third panel
on the order form. Paying is the step most likely to go wrong, and a separate
page can be reloaded, returned to after a prompt times out, or abandoned and
picked up from the confirmation email — none of which can re-post the order
or create a second one.

```
  order.php                     the order is SAVED here, and confirmed by
     │                          email + SMS before any money is discussed
     ▼
  pay.php                       choose M-Pesa or pay-on-handover
     │
     ├── cash   ──────────────► payment row 'pending', order 'unpaid'
     │                          → straight to tracking, kitchen starts
     │
     └── mpesa  ──────────────► payment row 'pending', order 'pending'
                                  │
                                  ▼
                        STK push → the customer's phone rings
                                  │
                      payment-callback.php  (Safaricom POSTs the result)
                                  │
                      ┌───────────┴───────────┐
                      ▼                       ▼
              settle_payment()        mark_payment_failed()
              order 'paid'            order 'failed'
              + receipt email/SMS     + "nothing was taken, try again"
```

The order being saved **before** payment is the important part. A customer
who closes the tab on the payment screen has not lost their food, and they
already have the reference in their inbox.

### The two payment drivers

| `PAYMENTS_DRIVER` | What happens |
|---|---|
| `manual` (default) | No gateway. An M-Pesa payment is recorded as pending and somebody at the till confirms it against the message on their own phone. Needs no credentials — and is what many small restaurants genuinely do. |
| `mpesa` | Safaricom Daraja STK push. Needs four credentials and a publicly reachable HTTPS callback, so it cannot be exercised from a laptop without a tunnel. |

If `mpesa` is selected but the credentials are blank, `payments_driver()`
falls back to `manual` rather than failing every checkout.

### Why there is no card form

There is deliberately no "type your card number" screen. Taking a card
number on our own server pulls the whole site into PCI scope and means
storing, logging and eventually leaking the most sensitive field a customer
has.

The right way to add cards is a redirect to the gateway's own hosted page
(Flutterwave, Paystack, Stripe Checkout), so the number is typed on *their*
domain and never touches ours. That slots in beside `mpesa` as another
method; it is not a form on this site.

### What gets sent, and when

| Moment | Email | SMS |
|---|---|---|
| Order saved | full confirmation, itemised, with a tracking link | reference + link, kept inside one message |
| Payment succeeds | receipt with the M-Pesa receipt number | short receipt |
| Tracking lookup | the six-digit code (if "email it instead") | the six-digit code |

Everything goes through `notify_send()`, which writes a `queued` row **before**
trying, hands it to the driver, then marks the row `sent` or `failed` with the
provider's own error text. Writing first means a request killed mid-send still
leaves evidence that it was attempted.

Nothing in `notify.php` throws. The order is already saved by the time we try
to tell anyone about it, and an email failing is not a reason to lose
somebody's lunch — the failure lands in the outbox instead.

### The mail drivers

| `MAIL_DRIVER` | What happens |
|---|---|
| `log` (default) | The message is written to `storage/mail/` as a `.eml` file and to the outbox. Nothing is sent. The whole flow works with no account and no internet. |
| `smtp` | Really sent, by talking SMTP to a mail server. |
| `mail` | Handed to PHP's `mail()`, which needs a local MTA. |

`src/mail.php` contains a hand-written SMTP client — connect, `EHLO`,
`STARTTLS`, `AUTH LOGIN`, `MAIL FROM`, `RCPT TO`, `DATA`, `QUIT` — in about
eighty lines. There is no composer and no `vendor/` in this project on
purpose, so that it stays something you can copy onto cheap shared hosting
and run; a mail library would have been the only reason to add one.

Three details in there are not obvious:

- **Dot-stuffing.** A lone `.` on its own line ends an SMTP message, so any
  line that genuinely starts with a dot gets a second one added. The server
  strips it back off. Without this, a message body could be truncated by its
  own punctuation.
- **It refuses to send a password over an unencrypted connection.** `AUTH
  LOGIN` is base64, which is an encoding, not encryption.
- **The server's own words are kept.** "535 Username and Password not
  accepted" tells you exactly what to fix; "could not send email" tells you
  nothing.

### SMS and phone numbers

Africa's Talking rather than Twilio, because the numbers are Kenyan:
delivery to Safaricom and Airtel is direct, it is priced in shillings, and
the sandbox is free.

`normalise_phone()` converts whatever the customer typed into `+254…`.
People write the same number every way there is — `0712 345 678`,
`254712345678`, `+254-712-345678` — and the provider accepts exactly one
spelling, so the site does the converting rather than making the customer
do it.

---

## 5. The journeys

### Reading the menu — `menu.php`

Read-only. One query joins dishes to courses, filters on `is_available = 1`,
orders by `display_order` then name, and the result is grouped by course in PHP.
`order.php` runs the equivalent query through `getOrderableMenu()`, so the two
pages always show the same dishes at the same prices.

### Placing an order — `order.php`

**Ordering requires an account.** The check is the first thing the page does,
before anything is read or written, so there is never a half-built order in a
session belonging to nobody:

```php
if (!logged_in()) {
    header('Location: account.php?mode=register&next=order.php&why=order');
    exit;
}
```

`?why=order` makes the account page explain itself rather than just appearing
in the way, and `?next=` brings them straight back.

The three contact fields are then **filled in from the account**, and stay
editable — "deliver to my sister's" is a real order. They are copied onto the
order row either way, because they are what was true at the time: a customer
who later changes their phone number has not changed the number the kitchen
rang that evening.

```
  POST
   │
   ├─ csrf_valid()?                      no → "Your session expired"
   ├─ buildOrderLines(quantities)        prices read from the database
   │    └─ anything dropped?             yes → refuse and name the dish
   ├─ at least one dish?
   ├─ name 2–100, valid email, phone given
   ├─ delivery? then address required
   ├─ delivery? then subtotal ≥ delivery_minimum (800)
   ├─ the time is one of order_times, and is still far enough ahead
   │     (now + lead_minutes, in the restaurant's timezone)
   │
   ▼  all clear
  BEGIN
    INSERT INTO orders (...)            reference from makeOrderReference()
    INSERT INTO order_items (...)       one per line
  COMMIT
   │
   ├─ remember the reference in the session
   ├─ notify_order_placed()            confirmation email + SMS
   └─ redirect to pay.php?ref=…
```

The two inserts are wrapped in a transaction, so an order can never exist
without its lines. Any failure rolls back, the real reason goes to the error
log, and the customer sees one plain sentence.

Money is computed on the server every time: `subtotal` from the lines,
`delivery_fee` only when the order is delivery, `total = subtotal + fee`.

**Two steps, by design.** "Confirm order" on the summary card does not submit —
it is a link to `#details` that moves the customer to the form. The button that
actually places the order sits at the foot of that form. With JavaScript off the
link still jumps there and the form still submits.

That button says **"Continue to payment"**, and the line under it says
"Nothing is charged until you do." A button labelled "Place the order"
followed by an unexpected payment screen reads as a trap.

**The time field opens on a slot the kitchen can cook.** The default used to
be the first slot of the day, which by lunchtime is hours gone — so the form
loaded with `11:30 am — too soon` selected and refused the order on submit
for a choice the customer never made. The default is now the first slot at
least `lead_minutes` away. A time the visitor picks themselves is never
silently moved; it is refused with a message.

### Booking a table — `reserve.php`

Three steps on one page: pick the date, time and party size; pick a table from
the plan; give your details.

The table grid is built by `getTablesWithAvailability()`, which marks a table
`taken` if a reservation already exists for that exact slot. Tables too small
for the party are shown but disabled.

Before writing, `reserve.php` re-checks that the table exists, is big enough and
is still free. Then it inserts — and catches `SQLSTATE 23000`:

```php
if ($ex instanceof PDOException && $ex->getCode() === '23000') {
    $errors['table_id'] = "Table {$table['label']} has just been booked…";
}
```

That is the race being handled honestly: the check narrows it, the unique key
closes it, and the loser gets a sentence they can act on.

### Tracking an order — `order-status.php`

Two ways in:

```
  just ordered                      coming back later
       │                                   │
  reference is in                    type the reference
  $_SESSION['my_orders']                   │
       │                             issue_access_code()
       │                             → six digits by SMS (or email)
       │                                   │
       │                             type the code
       │                             check_access_code()
       │                                   │
       └──────────► the order ◄────────────┘
```

**The code replaced "type the reference and the phone number."** That older
check only ever proved the typist *knew* a phone number — which is not a
secret; it is printed on the receipt. A guessed reference plus a guessed
number would have opened a stranger's name, phone, email and home address. A
code sent to the handset proves they are holding it.

The guards on the code, all tested in §7:

| Guard | Value |
|---|---|
| Lifetime | 10 minutes |
| Wrong guesses allowed | 5, then the code is dead |
| Codes per order | 4 per 15 minutes |
| Stored as | sha256 hash, never the digits |
| Compared with | `hash_equals()`, so the time taken leaks nothing |
| Older codes | invalidated when a new one is issued |

Issuing a code says *where* it went — `*******678`, `m****l@gmail.com` — but
never the whole number, so typing somebody's reference does not hand you
their contact details.

#### Saying it in plain words

The page's job is to answer "what is happening to my food", so that answer is
the headline and everything else is detail underneath it:

> **Your food is being cooked**
> It will be ready for Fri 6 Nov, 1:30 pm.

Each of the four kitchen stages carries a one-line explanation rather than a
bare status word — `preparing` left a customer to work out for themselves
whether they should set off:

| Stage | What the customer reads |
|---|---|
| `new` | Order received — we have it and the kitchen has been told. |
| `preparing` | Being cooked — your food is on the stove right now. |
| `ready` | Ready — cooked, packed and waiting for you. |
| `completed` | With you — handed over. Enjoy it. |

The current stage is marked with a **Now** badge as well as colour, so the
state is not carried by colour alone. Payment is stated in full — "Paid in
full by M-Pesa. Receipt SJK4LT9ZQ1" — because "Paid" on its own leaves
people wondering what they paid with.

---

### Accounts — `account.php`

Sign in and register are on one page, because somebody who cannot remember
whether they registered should not have to guess which page to be on. The two
tabs are **links that reload with `?mode=`**, not scripted toggles, so the
page works with JavaScript off like everything else here.

`?next=` brings you back where you were headed — checked against a list of
this site's own pages before it is used. Redirecting to an unchecked path
from a query string is an open redirect, which turns a trusted page into a
convincing way to bounce somebody to a phishing site.

**Registering claims your past orders.** `claim_orders_for_user()` attaches
any order whose email matches, *and only where `user_id IS NULL`*, so it can
never take an order that already belongs to somebody else. Without it, a
customer who ordered before accounts existed would lose their history the
moment they registered. The page then says how many it found, because a brand
new account that already has orders in it otherwise looks like a bug.

#### The forms sit on the page, not on a card

The fields have no container of their own: transparent background, no box, a
2px rule underneath. The form *is* the page's content, so a card around it
only draws a second edge inside the first.

Three things make it read as one list rather than several inputs:

- **head, switch and form share one `max-width` (480px)** and are centred in
  the content column, so every left edge lines up. Measured: five fields, two
  distinct left edges on a wide screen (the paired columns), and the labels
  sit on exactly those same two edges.
- **the underline is 2px at `#8a7659`** — 4.36:1 on white. A 1px hairline in
  a lighter brown would look tidier and would fail the 3:1 a UI boundary
  needs; the field's only edge has to be visible.
- **focus thickens and colours the rule and adds a faint wash** rather than
  an outline box appearing from nowhere.

Name and phone pair up on a wide screen, as do the two password fields;
everything collapses to a single column below 560px, where all five fields
share one left edge.

#### Showing a password

`script.js` section 8. The button is in the HTML **with `hidden` on it**, and
the script takes the attribute off — so a visitor with no JavaScript never
sees a control that cannot work, rather than the usual arrangement where the
button is always there and silently does nothing.

Flipping `type="password"` to `type="text"` is what reveals it. The input
keeps its id, name, value and `autocomplete` through the change, so a
password manager is not disturbed, and the caret position is restored so
revealing does not jump to the end of what you have typed.

`aria-pressed` carries the state for assistive software and the word Show /
Hide carries it for everyone else — the same fact, twice, so it is not
colour or iconography alone.

**A revealed password reverts on submit.** Otherwise a password left visible
survives the navigation and sits on screen on the next page.

#### What the login does not tell you

| Behaviour | Why |
|---|---|
| "That email and password do not match" for both a wrong password **and** an unknown address | saying which half was wrong tells an attacker whether an address has an account here |
| `password_verify()` runs even when there is no such account, against a throwaway hash | returning early would make a missing address answer measurably faster than a wrong password, which is a way to harvest addresses |
| 8 wrong tries, then 15 minutes locked — and the **correct** password is refused too | otherwise it is not a lockout |
| `session_regenerate_id(true)` on sign-in and on register | a session id an attacker fixed beforehand is not the one that ends up signed in |
| Signing out clears `my_orders` and `unlocked_orders` as well as `user_id` | on a shared machine the next person must not inherit a way into somebody else's order |

#### Ownership is a database fact, not a session memory

`may_view_order()` decides who may read an order, and the first thing it
checks is `orders.user_id === current_user()['id']`. The session says *who you
are*; the database says *what is yours*. That distinction is why signing in on
a second device shows the same orders, and why a stale session cannot keep
access to an order after the account is deleted.

Two narrower paths remain, both deliberate:

- `$_SESSION['my_orders']` — orders this browser placed moments ago, so
  `pay.php` works in the gap between placing an order and anything else;
- `$_SESSION['unlocked_orders']` — the one-time-code route, kept for orders
  taken **before** accounts existed. Those have no `user_id`, so there is no
  account to match them against. Registering with the same email claims them
  automatically, which is the usual way back in; the code is the fallback when
  the address differs.

---

### Tracking — what replaced the one-time code

The tracking page used to ask for a reference, text a six-digit code to the
phone on the order, and ask for that. It worked, and it made the commonest
thing a customer does — check on their food — the most laborious: find the
reference, wait for a text, type it in, and do it again tomorrow.

Now, signed in, `order-status.php` is a list of your orders, and `?ref=` is
one of them in full. Being signed in **is** the proof, so there is nothing to
type. The code machinery is still in the codebase for the legacy case above;
it is folded away behind a `<details>` rather than deleted.

---

### Keeping the two pages in step

The kitchen writes `orders.status`; the tracking page reads it. They were
always the same single source of truth — one table, one column, no copies to
reconcile. What was missing is that the customer had to reload to notice.

```
  kitchen.php                       order-status.php
  ─────────────                     ────────────────
  press "Mark ready"
      │
      ▼
  move_order()  ── FOR UPDATE lock ──┐
      ├─ UPDATE orders.status        │ one transaction
      └─ INSERT order_events         │
                                     ▼
                              orders.status = 'ready'
                                     │
      ┌──────────────────────────────┴───────────────┐
      ▼                                              ▼
  board reloads every 45s                 page reloads every 30s
  (meta refresh)                          (meta refresh, while live)
      │                                              │
      ▼                                              ▼
  ticket shows "Ready"              "Your food is ready to collect"
                                    + SMS and email already sent
```

Both halves use `<meta http-equiv="refresh">` rather than polling from
script — a kitchen tablet on a shelf and a phone with the tab in the
background both have to keep working, and neither can depend on JavaScript
being alive.

The customer's page refreshes **only while the order is live**. A finished or
cancelled order will never change again, so it says so and stops reloading
rather than hammering the server for ever.

The kitchen ticket also shows an **Account** or **Guest** chip, so staff can
answer "where do I see it?" with "it is on your orders page" instead of
talking somebody through a reference.

---

### Running the kitchen — `kitchen.php`

The staff side, and the thing that makes every stage on the tracking page
mean something. Before it existed, no order ever left `new`.

```
  new  ──Start cooking──►  preparing  ──Mark ready──►  ready
                                                         │
                                                  Mark collected
                                                  (or Mark delivered)
                                                         ▼
                                                     completed

  new / preparing / ready  ──Cancel──►  cancelled
```

**Forwards only, plus cancel.** There is no going back. An order handed to a
customer cannot become "being cooked" again, and a screen that allows it
invites a mis-tap to rewrite history. A genuine mistake is a new order, or a
cancellation with a note.

The allowed moves live in `order_next_statuses()` in `src/staff.php`, and
`move_order()` re-checks them **against the row as it is in the database,
inside a `FOR UPDATE` lock** — not against whatever the page was showing. Two
cooks on two tablets will both have a stale screen eventually, and the second
tap must not be able to drag an order backwards. The status change and its
history row are written in one transaction, so there is no such thing as a
move without a record of it.

#### What the customer is told

Only the two moments that change what they should *do*:

| Move | Message |
|---|---|
| `ready` | "order MD-… is ready to collect. Quote MD-… at the counter." — or "is on its way. Keep your phone nearby." for delivery |
| `cancelled` | "order MD-… was cancelled and you have not been charged." |
| `preparing` | **nothing** |
| `completed` | **nothing** |

"We have started cooking" is of no use to anybody and every SMS costs the
restaurant money. Both silent stages are still visible on the tracking page.

#### Money is a separate button

"Took payment" is not part of the status flow, because handing over food and
taking the cash are two different moments and on a busy counter they happen
in either order. It goes through the same `settle_payment()` as the M-Pesa
callback, so it gets the same row lock, the same idempotency and the same
receipt — pressing it twice on two tablets sends one receipt.

#### Deleting a cancelled order

Cancelled orders are the ones that pile up as clutter, so they are the ones
that can be cleared. Nothing else can: a live order is the kitchen's work
list and a finished one is the restaurant's trading record.

**It takes two deliberate presses.** The first is a *link* carrying
`?confirm=<reference>`, which re-renders that one ticket with the real button
on it and names the order in the question — so there is no chance of
confirming the wrong one. No dialog and no script, so it behaves the same on
a tablet with JavaScript off.

**A paid order is refused.** `payments` cascades on delete, so removing an
order that took money would destroy the only record of that money — and a
cancelled-but-paid order is precisely the one that still owes somebody a
refund. The ticket says "Cancelled, but paid — refund this before clearing
it" instead of offering a button. The check reads both `orders.payment_status`
*and* the `payments` table, because where money is concerned the detailed
record is the one to believe.

**What goes with it**, by the foreign keys: the dishes, the payment attempts,
the status history and any tracking codes. `notifications` is
`ON DELETE SET NULL`, so the record of what was emailed and texted survives
with its `order_id` blanked.

**The deletion is logged first.** `order_events` cascades too, so afterwards
the database holds no trace of the order at all — the line written to
`error_log()` before the `DELETE` becomes the only evidence it existed,
carrying the reference, the customer, the total and the payment state.

#### Built for a tablet with flour on it

- **No JavaScript.** Every action is a form POST; the board keeps itself
  current with `<meta http-equiv="refresh" content="45">`. A tablet left on a
  shelf has to keep updating, and that must survive scripting being off,
  broken, or still loading. The refresh is only on the live board — on the
  sign-in page it would wipe a half-typed password.
- **Buttons are 48px minimum**, measured at 49px. They get pressed with the
  side of a thumb.
- **Tickets are the site's `.card`** — same white surface, same hairline, same
  14px radius, same shadow as a dish card or the order summary — so a ticket
  reads as the same kind of object as everything else on the site. There is no
  coloured rail down the side: urgency is carried by a chip that says the
  minutes in words (`12 min late`), which survives being printed, photocopied,
  or read by somebody who cannot tell red from brown. A late ticket also
  tints its head strip, because a chip alone is too small to find while
  scrolling.
- **One column, stacked.** Tickets run down the page in due order rather than
  across a grid, so finding the next thing to cook is reading in one
  direction. The column is capped at 68rem and centred: full-bleed tickets on
  a wide monitor leave the dishes on the far left and the money on the far
  right, and a cook reads neither. Inside each ticket the body splits
  1.6fr / 1fr — dishes for the kitchen, contact and money for the counter —
  collapsing to one column under 680px.
- **Soonest first**, not newest first. The kitchen works in the order food is
  needed, not the order it was typed in.
- **Three counts in the bar** — Waiting / Cooking / Ready — at 24px, readable
  across a room, and the page `<title>` carries the number so it shows on a
  browser tab.
- **It carries the logo and a breadcrumb trail**, but not the site
  navigation: the lockup doubles as the way back out, and the trail says
  Home / Kitchen. There is still no menu, no Order button and nothing else
  that invites a customer to wander in.
- **It prints.** `@media print` drops the bar, the buttons and the history, so
  a ticket can go on a spike.

---

## 6. What protects the data

**SQL injection.** Every value reaches the database through a placeholder.
Emulated prepares are turned off, so these are real server-side prepares. One
statement builds SQL by concatenation — `SET time_zone = '+03:00'` in
`getPDO()` — and the value is generated by PHP's own date handling, never by a
request.

**Cross-site scripting.** Everything printed goes through `e()`. No page echoes
`$_GET`, `$_POST` or `$_SERVER` unescaped.

**Cross-site request forgery.** Every form carries a token and checks it with
`hash_equals` before doing anything — ordering, booking, paying, and both
steps of the tracking lookup.

**Price tampering.** Prices are never read from the request. See §4. The same
rule applies to money arriving from a payment provider: the amount charged is
the one on our own order, never the number in the callback body, because that
URL is public and anybody can POST to it.

**Double-booking.** A unique key in the database, not a check in PHP. See §3.

**Double-charging.** `settle_payment()` takes a `FOR UPDATE` row lock and
returns early if the payment is already `paid`, so a retried callback cannot
credit twice or send two receipts. Underneath that, `payments.provider_ref`
is `UNIQUE`, so a duplicate receipt number cannot be inserted at all.

**Duplicate submission.** Every successful POST redirects.

**Reading somebody else's order.** A reference alone opens nothing. `pay.php`
requires the session that placed the order and otherwise redirects to the
tracking page without hinting whether the reference exists; tracking requires
a one-time code sent to the phone on the order.

**Email header injection.** A newline in a subject, recipient or From name
would let the rest of the string become headers of its own — a `Bcc:`, for
instance. `build_email_message()` strips CR, LF and NUL from all of them. It
is done in the function that *builds* the headers, not only in the caller, so
a caller that forgets is not a security hole.

**One-time codes.** Hashed at rest, expiring, attempt-capped, compared with
`hash_equals`. See §5.

**The session cookie.** `httponly` so a cross-site scripting bug cannot read
it, `samesite=Lax` so the browser does not attach it to requests another site
started, and `secure` only when the request is already HTTPS — setting it
unconditionally would make a local HTTP dev server hand out a cookie the
browser then refuses to send back.

**Credentials.** `src/config.php` and `src/secrets.php` are both outside the
web root and gitignored; `config.sample.php` and `secrets.example.php` are the
templates, committed with the blanks empty so a new developer can see which
values are needed without any of them being published. Every secret can
instead come from an environment variable, and the environment wins — which
is the better arrangement on a real server, since a password in an
environment variable is not sitting in a file a misconfigured web server
could serve as text.

**Customer accounts.** Covered in §5, and the short version: bcrypt only, one
message for both halves of a failed login, a dummy `password_verify()` so a
missing address does not answer faster, 8 tries then a 15-minute lockout
counted by IP *and* by email, a fresh session id on sign-in, and ownership
decided by `orders.user_id` rather than by what the session remembers seeing.

**One rate limiter, two doors.** `login_attempts` carries a `scope`, and both
`accounts.php` and `staff.php` go through `login_failures()` /
`login_record()`. Both fail **closed**: if the limiter's own query throws, the
functions return a count that locks the door rather than one that opens it. A
login that fails open because its rate limiter broke is the wrong way round.

**The kitchen screen's own login.** This is the most attackable thing on the
site: one password stands between the internet and every customer's name,
phone number and home address.

| Measure | Why |
|---|---|
| Only a bcrypt hash is stored, from `password_hash()` | there is nowhere — code, config or database — that a readable password exists |
| `password_verify()` | constant-time, and it reads the cost and salt out of the stored hash |
| 6 wrong tries per IP, then 15 minutes locked | a session counter is no defence at all; an attacker's script simply does not send the cookie back |
| Counted in the database, keyed on `REMOTE_ADDR` | **not** `X-Forwarded-For` — anybody can send that header, so trusting it would let an attacker give themselves a fresh identity on every request and walk straight past the limit |
| The lockout refuses the *correct* password too | otherwise it is not a lockout |
| `session_regenerate_id(true)` on sign-in | a session id an attacker fixed beforehand is not the one that ends up signed in |
| Signed out after 8 hours | a forgotten tablet on a counter should not stay open all night |
| `X-Robots-Tag: noindex`, `Cache-Control: no-store, private`, `Referrer-Policy: same-origin` | keeps it out of search results and out of a shared browser's cache |

**If no password is configured the screen refuses to open.** It does *not*
fall back to "no password needed" — a half-installed site would then be
publishing its whole customer list. On `localhost` it prints the three setup
steps; anywhere else it says only that the screen is not available, because
naming the config file would tell an attacker exactly what to go looking for.

**The password can be switched off**, with `'require_password' => false`, so a
development machine goes straight to the board. Three things make that a
deliberate act rather than an accident:

- it defaults to **true** when the setting is absent, so a config file written
  before it existed does not silently open;
- anything other than an explicit off word (`false`, `0`, `no`, `off`) counts
  as on, so a typo fails safe;
- the open board carries a standing, non-dismissible warning naming exactly
  what is exposed — and an extra line when the host is not localhost.

What does **not** change when it is off: CSRF tokens are still checked on
every action, and the transition rules are still enforced inside the row
lock. Signing in is the only thing removed; a hostile page on another site
still cannot drive the board.

**The status value is whitelisted before use.** `move_order()` would refuse an
unknown status anyway, but its refusal message quotes what was asked for — and
that message should never be assembled from an arbitrary posted string.

**The password tool is command-line only.** `tools/staff-password.php` exits
404 if `PHP_SAPI` is not `cli`; reachable over HTTP it would be a public
admin-credential generator. It reads the password with `stty -echo` rather
than taking it as an argument, so it never lands in `~/.bash_history`.

**The development payment shortcut.** `pay.php` can show a "simulate a
successful payment" button, because with no gateway configured there is
otherwise no way for a payment to complete and the rest of the flow cannot be
tested. It renders only when **both** no gateway is configured **and** the
request came from `localhost`, `127.0.0.1`, `::1`, `*.localhost` or `*.test`.
On a real host it does not exist, so it cannot be used to mark an order paid.

**Error messages.** Visitors get one plain sentence. The real exception goes to
`error_log()`. The one exception is the payment callback, which always answers
`200` even when our own database is down: Safaricom retries anything else for
hours, and the retries would not help, because the fault is at our end.

---

## 7. What was tested, and what it showed

Run against the live database on 21 October 2026. The sections on payment,
email and tracking codes were added when those features were.

### Order validation

| Posted | Result |
|---|---|
| A complete, valid order | accepted, redirected to the status page |
| No dishes | refused — "Choose at least one dish." |
| No name | refused — "Enter your name (2 to 100 characters)." |
| An invalid email | refused — "Enter a valid email address." |
| Delivery with no address | refused — "Enter the address we are delivering to." |
| Delivery below the KSh 800 minimum | refused, and told how far short it is |
| A collection time not on the list | refused — "Choose one of the collection times." |
| A date in the past | refused — "That time has already passed." |
| A dish id that does not exist | refused — no order written |
| Quantity of 999 | **accepted and clamped to 20** (stored 20 × 450 = 9,000) |
| No CSRF token | refused — "Your session expired." |

### Reservation validation

| Posted | Result |
|---|---|
| A valid booking | accepted |
| The same table and slot again | refused — "has just been booked" |
| A party too big for the table | refused |
| No table chosen | refused — "Choose a table." |
| No name / an invalid email | refused with the matching message |
| A table id that does not exist | refused |

### Concurrency

- **Five simultaneous bookings of the same table and slot** → exactly one row
  stored. The other four were told the table had just been taken.
- **Six simultaneous orders** → six rows, six distinct references, no collision.

### Data integrity — 14 of 14 clean

No orphaned order lines; no line pointing at a missing dish; no dish without a
category; no reservation on a deleted table; no order where
`total ≠ subtotal + delivery_fee`; no order whose lines do not add up to its
subtotal; no order without lines; no quantity below 1 or above the cap; no
negative price; no duplicate reference; no double-booked table; no delivery
order missing an address; no collection order carrying one.

Live data at the time of writing: 5 orders, 8 order lines, 1 reservation,
3 payments, 8 notifications, 12 dishes, 19 tables. (The database was rebuilt
from `schema.sql` + `seed.sql` on 21 October after the development instance
was lost, so the earlier order history is gone; the menu and the tables came
back from the seed.)

### Payment

| Done | Result |
|---|---|
| Order placed | redirected to `pay.php`, confirmation email + SMS recorded `sent` |
| Chose M-Pesa | payment row `pending`, order `pending`, phone normalised `0712345678` → `+254712345678` |
| Settled it | payment `paid`, order `paid`, receipt email + SMS sent |
| Chose pay-on-collection | order `payment_method=cash`, `payment_status=unpaid` — the honest state, since the food is coming and the money has not arrived |
| `settle_payment()` called 3× for one payment | 1 receipt email, 1 credited row, order `paid` |
| A second row claiming the same `provider_ref` | refused by the database — `SQLSTATE 23000` |
| Callback, `ResultCode 0` | `paid`, receipt number `SJK4LT9ZQ1` stored |
| Callback, `ResultCode 1032` (customer cancelled) | `failed`, note "Request cancelled by user" |
| Callback with a body that is not JSON | still answered `200` |
| Already-paid order opened in `pay.php` | redirected to the receipt, not offered a second charge |

### Email

| Done | Result |
|---|---|
| `log` driver | `.eml` written to `storage/mail/`, outbox row `sent` |
| `smtp` driver against a real SMTP server | **sent** — correct `EHLO` / `MAIL FROM` / `RCPT TO` / `DATA` / `QUIT`, multi-line `250-` continuations parsed |
| The message that arrived | `multipart/alternative` with both parts, `Date` in `+0300`, subject base64-encoded because of its em dash |
| A body line starting with `.` | arrived intact — dot-stuffing applied and stripped correctly |
| Nothing listening on the port | `Could not reach 127.0.0.1:2526 — Connection refused` |
| A username set with `encryption=none` | refused — "Refusing to send a password over an unencrypted connection"; nothing reached the wire |
| An invalid recipient | refused before any connection was opened |
| `Subject: Hi\r\nBcc: attacker@…` | no header contains a newline, no `Bcc` header created — the text lands as one harmless literal line |

### Tracking codes

| Done | Result |
|---|---|
| Correct code | order opened, code marked used |
| Five wrong guesses | counted down "4 tries left" … then "that code is now dead" |
| The correct code after the lockout | refused |
| A correct but expired code | refused |
| A fifth code request inside 15 minutes | refused — "We have sent several codes already." |
| Wrong code | page leaked nothing of the order — 0 matches for the dish or address |
| What is stored | a sha256 hash; the digits appear only in the sent message |

### Phone normalisation — 9 of 9

`0712345678`, `0712 345 678`, `+254712345678`, `254712345678`, `712345678`
and `+254-712-345678` all became `+254712345678`. `123`, `""` and `abc` all
became `null` rather than being sent into the void.

### Accessibility and contrast — 36 of 36

Every new foreground/background pair was measured, including the ones that sit
on a translucent layer (the field wells are `rgba(0,0,0,.18)` over the brown,
so the effective colour was composited before measuring). All text pairs clear
4.5:1 and all UI boundaries clear 3:1. The tightest were the ghost button's
border at 3.08:1 and a disabled dropdown option at 4.87:1.

### Accounts, ownership and sync

| Done | Result |
|---|---|
| Register | account created, bcrypt `$2y$12$` hash stored |
| Duplicate email | refused — "There is already an account with that email", no second row |
| Password under 8 / mismatch / bad phone / bad email / 1-char name | each refused with its own message; only the valid one created a row |
| `order.php` signed out | `302` to `account.php?mode=register&next=order.php&why=order` |
| `order.php` signed in | name, email and phone prefilled from the account |
| Order placed | `orders.user_id` set, order appears in "my orders" with no reference and no code typed |
| Sign out, sign in again | the same orders listed |
| **Another account** opening that order's `?ref=` | **0 matches** for its dishes, notes or phone; shown "No orders yet" |
| Signed out entirely opening `?ref=` | **0 matches** |
| Account isolation | account 1 → 1 order; account 4 → its own 5. No crossover |
| Wrong password vs unknown address | **identical** message, so neither reveals whether an account exists |
| 8 wrong tries on one account | locked — "Try again in 14 minutes" |
| The **correct** password while locked | refused |
| Claiming past orders | registering with an address that had 5 unowned orders attached all 5, and left another customer's two alone |

### Deleting a cancelled order

| Done | Result |
|---|---|
| Delete a **live** order | refused — "Only a cancelled order can be deleted. … is new — cancel it first." |
| Delete a **cancelled but paid** order | refused — "…would destroy the only record of the money. Refund it first" |
| Delete a cancelled, unpaid order | deleted |
| Cascade afterwards | 0 orphaned `order_items`, 0 orphaned `order_events` |
| Delete the same reference again | `ok` — already gone is not an error |
| Following the Delete **link** | shows the confirmation, order still in the database |
| Delete POST with a bad CSRF token | refused, order still there |
| The real confirm | `302`, row gone, "DEL-UI02 deleted." on the board |

### The auth forms and the password reveal

| Done | Result |
|---|---|
| Fields inside a `.card`? | **no** — transparent background, no top border, 2px bottom rule |
| Alignment at 1280px | 5 fields, 2 distinct left edges, labels on exactly those same edges |
| Head / switch / form | all centred in the content column, all sharing one 480px max-width |
| At 420px | one column, one left edge (16px), 0 overflowing elements, no sideways scroll |
| Reveal button in the raw HTML | both carry `hidden`, so no-JS shows no dead button |
| Click | `type` → `text`, label → "Hide", `aria-pressed` → true, `aria-label` → "Hide password", value intact |
| Click again | back to `password` / "Show" / false |
| Revealed, then submitted | reverted to `password` |
| Contrast | 24 checks, 0 failing |

### The kitchen and the customer, in step

The same order, watched from the customer's session while the kitchen pressed
each button:

| Kitchen pressed | Customer's headline | Auto-refresh |
|---|---|---|
| *(just ordered)* | We have your order | 30s |
| Start cooking | Your food is being cooked | 30s |
| Mark ready | Your food is ready to collect | 30s |
| Mark collected | All done — thank you | **off** — "this order is finished, so it will not change again" |

### The kitchen screen

| Done | Result |
|---|---|
| Opened with no password configured | refused; printed the setup steps (localhost only) |
| Requested the board with no session | **no customer data in the response at all** — 0 matches for any reference, name or phone |
| Forged a `move` POST with no session | order stayed `new` |
| One wrong password | "That is not the right password. 5 tries left." |
| Six wrong passwords | locked out — "Try again in 14 minutes" |
| The **correct** password while locked out | refused |
| Correct password, not locked | signed in, session id regenerated, redirected |
| `new → preparing → ready → completed` | each saved, each wrote an `order_events` row |
| `completed → preparing` / `new` / `ready` / `cancelled` | all four refused — "already completed, so it cannot be moved to …" |
| `to=deep-fried` | refused — "That is not a status an order can be in." |
| `to=<img src=x onerror=…>` | refused, and nothing echoed into the page |
| Cancel | status `cancelled`, cancellation email + SMS sent |
| "Took payment" on an unpaid order | `payment_method=cash`, `payment_status=paid`, receipt sent |
| "Took payment" pressed twice | one receipt, not two |
| Notifications per stage | `ready` and `cancelled` only; `preparing` and `completed` silent |

### The customer's page follows the kitchen

The same order, watched from the customer's own session while the kitchen
pressed each button:

| Kitchen pressed | Customer's headline | Their next step |
|---|---|---|
| *(just ordered)* | We have your order | The kitchen starts in good time for Wed 21 Oct, 5:30 pm. |
| Start cooking | Your food is being cooked | It will be ready for Wed 21 Oct, 5:30 pm. |
| Mark ready | Your food is ready to collect | Come to the counter and quote MD-Y8XZZ3. |
| Mark collected | All done — thank you | Your order is finished. We hope it was good. |

### Kitchen layout — measured, not eyeballed

Rebuilt white and single-column, then re-measured:

| Width | Ticket | Inner split | Smallest touch target | Sideways scroll |
|---|---|---|---|---|
| 1280px | 1032px, stacked | 589 / 368 | 49px | none |
| 760px | 720px, stacked | 397 / 248 | 49px | none |
| 375px | 335px, stacked | one column | 49px | none |

The top bar stays `sticky` at every width, and the ticket's computed style
matches the site's `.card` exactly — `rgb(255,255,255)`, `1px rgb(230,221,209)`
on all four sides, `14px`, and the `--shadow` token. The logo renders at 40px
with the wordmark visible in `rgb(26,20,16)` and its three E bars drawn.

With every history panel forced open at 375px: **zero** overflowing elements.
That check matters because `.kticket` is `overflow: hidden`, so an overflow
there would be silently *clipped* rather than showing as a scrollbar.

Contrast was measured twice — once for the dark palette, then again after the
rebuild. The light palette is **56 + 16 checks**, with three fixed along the
way: the Cancel button's border at 2.06:1 and the "Took payment" border at
2.25:1 (both are the button's only visible edge, where a boundary needs 3:1),
and the breadcrumb separator at 2.86:1.

### Working without JavaScript

Every flow test above was driven with `curl`, which runs no JavaScript at all.
Ordering, choosing a payment method, settling a payment, requesting a code,
unlocking an order, signing in to the kitchen and moving an order through all
four stages therefore all work with scripting off — the script file only adds
the stepper, the live totals and the page transitions, and the kitchen board
has no script at all.

---

## 8. Configuration — the three files to edit

**`src/config.php`** — database host, name, user, password.

**`src/secrets.php`** — mail, SMS and payment credentials. Copy
`secrets.example.php` and fill in the blanks; every value can instead be
supplied as an environment variable, which wins over the file. With no
`secrets.php` at all, every driver is `log`/`manual` and the whole site still
works end to end — nothing leaves the machine.

**`src/content.php`** — everything the restaurant owns, with no PHP to touch:

| Key | What it controls |
|---|---|
| `name`, `tagline`, `about` | the wording across the site |
| `hero_dish` | which dish appears on the big plate on the home page |
| `address`, `phone`, `email` | contact details, used in several places |
| `timezone` | the clock every date and time is judged against |
| `map` | latitude, longitude and zoom for the map on the home page |
| `slots` | the seatings people can book |
| `order_times` | the collection and delivery times |
| `hours` | the opening hours |
| `ordering.lead_minutes` | the shortest notice the kitchen accepts (45) |
| `ordering.delivery_fee` | the flat fee (200) |
| `ordering.delivery_minimum` | the smallest order to deliver (800) |
| `ordering.max_per_dish` | the cap on one dish (20) |
| `payment.paybill` / `payment.till` | the M-Pesa number customers send money to |
| `payment.account` | what to type in the provider's "account" box |

`hero_dish` must match a dish name exactly; if it does not, the home page falls
back to the cheapest dish rather than breaking.

`payment.paybill` and `payment.till` are **deliberately left empty**. A
made-up paybill number is somebody else's real account, and an order paid
into it is money gone. While both are empty the payment screen says to pay on
collection instead, which is true and harmless.

### The environment variables

| Variable | Default | Notes |
|---|---|---|
| `STAFF_PASSWORD_HASH` | — | bcrypt hash for `kitchen.php`; while empty the screen refuses to open |
| `MAIL_DRIVER` | `log` | `log` / `smtp` / `mail` |
| `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO` | — | with Gmail, `MAIL_FROM` must match `SMTP_USERNAME` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` | `587`, `tls` | `tls` for 587, `ssl` for 465 |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | — | **Gmail needs an App Password**, not the account password |
| `SMS_DRIVER`, `SMS_FROM` | `log` | `log` / `africastalking` |
| `AT_USERNAME`, `AT_API_KEY` | `sandbox` | africastalking.com |
| `PAYMENTS_DRIVER` | `manual` | `manual` / `mpesa` |
| `MPESA_ENV`, `MPESA_SHORTCODE`, `MPESA_PASSKEY`, `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, `MPESA_CALLBACK_URL` | — | Daraja; the callback must be public HTTPS |
| `BASE_URL` | guessed from the request | needed for links in mail sent off-request |

---

## 9. The front end's contract with the back end

**Everything works with JavaScript switched off.** `script.js` only improves
things that already function:

- the quantity steppers wrap a plain number input;
- the running total is recalculated as you click, but **PHP recalculates it
  from scratch on submit and that figure is the one stored**;
- "Confirm order" is a link to the form, not a scripted button;
- the sliding navigation, the hero animation and the page fades are decoration.

Anything the script computes is treated as a hint. The server never trusts it.

---

## 10. What still needs doing

Honest list, in the order I would do it.

1. **No "forgot my password".** An account whose password is lost cannot be
   recovered — there is no reset email. Everything needed to build it is
   already here (the mailer, the outbox, and the hashed-token-with-expiry
   pattern in `order_access_codes`), so this is assembly rather than design,
   but until it exists a forgotten password means a new account.

2. **Nothing verifies an email address.** Registration trusts what was typed,
   so a typo means confirmations go nowhere and the customer never finds out.
   The same token pattern would cover it.

3. **No real credentials are configured**, so email and SMS are written to the
   outbox and `storage/mail/` rather than sent, and M-Pesa is `manual`. The
   code paths are built and tested (§7) — this is filling in
   `src/secrets.php`. For email the quickest route is a Gmail App Password;
   for SMS, an Africa's Talking sandbox key.

4. **The M-Pesa callback cannot be reached from a laptop.** Daraja POSTs to a
   public HTTPS URL, so testing the real driver needs a tunnel (ngrok or
   similar) and `MPESA_CALLBACK_URL` set to it.

5. **Decide how errors are displayed in production.** The app never sets
   `display_errors`, so it follows `php.ini`. On a live server it must be off,
   with `log_errors` on.

6. **The address and map coordinates are still placeholders** — `Main Street,
   Your Town`, and a pin in the middle of Nairobi.

7. **No rate limiting on the order and booking forms.** Nothing stops a script
   placing orders in a loop. Both login doors *are* limited, and
   `login_failures()` / `login_record()` are generic enough that the writing
   forms could use them with a third scope.

8. **Nobody tells the kitchen.** The customer now gets an email and an SMS, but
   no message goes to the restaurant when an order arrives. One more
   `notify_send()` call with the restaurant's own address would do it.

9. **The outbox is never pruned.** `notifications` keeps every message body
   forever, including customers' addresses. It needs a retention rule — delete
   anything older than, say, 90 days.

10. **The kitchen password is currently switched off** on the development
   machine (`'require_password' => false`). It must be turned back on, with a
   password set, before the site has a public address.

11. **One shared kitchen password, not staff accounts.** `order_events.actor`
   records every move as `kitchen`, so the history can say *when* but not
   *who*. The column is already sized for a name; what is missing is a staff
   table and a login that fills it in. Worth doing before the history is ever
   needed to settle an argument.

12. **Cancelling takes no reason.** The `order_events.note` column exists and
   is left empty by the screen. A one-line "why" on the cancel button would
   make the audit trail far more useful than a bare `cancelled`.

13. **The kitchen screen is not linked from anywhere.** That is deliberate —
    a link would advertise the admin URL — but it means staff have to be told
    it is at `/kitchen.php`.

---

## 11. Things that were fixed along the way

Recorded because each one was invisible until it was looked for, and each is
the kind of thing that comes back.

**The Time field looked empty when opened.** The dark form sets `color: #fff`
on its fields, and a `<select>`'s drop-down list is drawn by the browser, not
by the page — so the options inherited white text and kept the system's white
popup. `color-scheme: dark` repaints the popup, the date picker and their
icons; explicit `option` colours cover the engines that ignore it.

**The dropdown arrow disappeared on focus.** The shared focus rule uses the
`background` *shorthand*, which resets `background-image` to `none`, and being
`.form-brown .field__select:focus` it outranks the two-class rule that draws
the chevron. Found by reading the live cascade in the browser, not by looking
at the file. The chevron is now re-stated at equal specificity, later.

**The time field defaulted to a slot that was already refused.** See §5.

**A hidden input inside a `<button>`** does not submit. The "email it instead"
control needed to be its own form, because a button carries one name/value
pair and `action` had already claimed it.

**`{$placeholder}` inside a heredoc** is interpolated as a variable while the
string is built, so it was gone before `str_replace` ran. The item list in the
confirmation email now uses `@@ITEMS@@`.

**`build_email_message()` trusted its caller** to have stripped newlines out of
the subject. The real path did, but the guarantee belonged in the function that
builds the headers.

**An empty `CSSRuleList` is truthy**, which made a stylesheet walker recurse
into every leaf rule and find nothing. Worth remembering when inspecting CSS
from the console: test `.length`, not the object.

**`payment_status` could be `paid` with `payment_method` still `NULL`.**
`settle_payment()` only ever set the status, because the M-Pesa path had
already written the method when the push went out. An order paid at the
counter that had skipped the payment screen therefore ended up "paid, by
nothing", which showed as a blank on the receipt and the board.

**New orders had no opening history row.** The backfill in `upgrade.sql` gave
one to every order that already existed, but `order.php` was not writing one,
so the kitchen screen showed a timeline for new orders that began halfway
through. It is now written inside the same transaction as the order itself.

**The kitchen page never loaded the webfonts.** It is its own document, so it
did not inherit `header.php`'s font links — the wordmark fell back from
Poppins 700 to a system sans and read noticeably thinner than the same logo
everywhere else, and the M in the box lost Anton entirely. Verified fixed by
comparing the computed `font-family` and `font-weight` of both logos: Poppins
700 and Anton 400 on each.

**Breadcrumbs sat underneath the rail's pull-in button**, which is
`position: fixed` exactly where the trail begins. Measured in both rail
states before and after.

**`lastInsertId()` is per-connection, not per-statement.** An `UPDATE` run
after an `INSERT` on the same PDO handle resets it to `0`, so the id has to be
read immediately after the insert.
