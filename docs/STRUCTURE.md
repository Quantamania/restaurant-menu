# Maridadi Eat — how the project is arranged

A guide to the files: what each one is for, why it sits where it does, and how
a request travels through them. Written to be read alongside the code, and to
be defended out loud.

**Companion documents**

| | |
|---|---|
| [PRESENTATION.md](PRESENTATION.md) | How the system works, step by step |
| [DEMO-SCRIPT.md](DEMO-SCRIPT.md) | What to do and say while demonstrating it |
| [README.md](README.md) | Setting it up and running it |
| [DEPLOY.md](DEPLOY.md) | Putting it online |

---

## 1. The one rule

> **A page lives at the top level. Its parts live in a folder named after it.**

That is the whole layout. `order.php` is the ordering page; the sections it is
built from are in `includes/sections/order/`. `kitchen.php` is the kitchen
screen; its sections are in `includes/sections/kitchen/`.

Open any page, read its `require` lines, and you know exactly which files it
is made of — and the folder name tells you which page a section belongs to
without opening it.

---

## 2. The tree

```
maridadi-eat/
│
├── index.php              the home page
├── menu.php               the menu, read from the database
├── order.php              choose dishes, pick a collection time
├── pay.php                M-Pesa, or pay on collection
├── order-status.php       track an order, cancel it, delete it
├── reserve.php            book a table
├── account.php            register and sign in
├── kitchen.php            the staff board — live work only
├── history.php            finished orders and past bookings
├── payment-callback.php   where M-Pesa reports back
│
├── includes/              everything shared, never opened directly
│   ├── setup.php              runs first on every page
│   ├── functions.php          the database connection and small helpers
│   ├── orders.php             the life of an order and of a booking
│   ├── auth.php               customer accounts, and the staff door
│   ├── payments.php           taking money
│   ├── messaging.php          email and SMS
│   ├── session.php            where sessions are kept
│   ├── content.php            wording, phone number, opening hours
│   ├── header.php             the top of every customer page
│   ├── footer.php             the bottom of every customer page
│   ├── config.php             the database password — never committed
│   ├── config.example.php     the version that is safe to share
│   │
│   └── sections/          one folder per page
│       ├── index/             hero.php · about.php · hours.php
│       ├── order/             dishes.php · details.php · basket.php
│       ├── order-status/      one-order.php · my-orders.php · find-order.php
│       ├── reserve/           booking-form.php · my-bookings.php
│       ├── account/           signed-in.php · sign-in-forms.php
│       ├── pay/               choose-method.php
│       ├── kitchen/           order-board.php · bookings.php · staff-door.php
│       └── history/           past-orders.php · past-bookings.php
│                              left-the-board.php
│
├── css/style.css          every style on the site
├── js/script.js           optional extras; the site works without it
├── images/                the 12 dish photos
├── database/              schema.sql (tables) · seed.sql (starting data)
├── tools/                 command-line helpers, not part of the website
├── docs/                  these documents
└── vercel.json            hosting configuration
```

---

## 3. Why the pages are at the top level

**The URL is the filename.** Opening `/menu.php` runs `menu.php`. There is no
router, no rewrite rules, no controller mapping — so there is nothing to
explain about how a request finds its code, and nothing to misconfigure.

Three things follow from that:

1. **It installs anywhere.** Copy the folder into XAMPP's `htdocs` and open
   `http://localhost/restaurant-menu/`. Every path in the HTML is relative
   (`css/style.css`, not `/css/style.css`), so a subfolder works as well as a
   domain root.
2. **`php -S localhost:8000` is the whole development setup.** The project
   root is the web root, so there is no document-root flag to get wrong.
3. **A missing page is a missing file** — which is exactly what a 404 should
   mean.

---

## 4. Why each page is split

A page used to be one long file. `order-status.php` was 864 lines, and you
could not see its shape without scrolling through all of it.

The split follows the branches that were **already in the code**. That page
shows one of three things, and never two at once:

```php
<?php if ($order): ?>          one order, tracked
<?php elseif ($me): ?>         everything this account has ordered
<?php else: ?>                 sign in, or look one up by reference
<?php endif; ?>
```

Each branch became a file. The page kept the logic and now reads as a table of
contents:

```php
<?php if ($order): ?>
  <?php require __DIR__ . '/includes/sections/order-status/one-order.php'; ?>
<?php elseif ($me): ?>
  <?php require __DIR__ . '/includes/sections/order-status/my-orders.php'; ?>
<?php else: ?>
  <?php require __DIR__ . '/includes/sections/order-status/find-order.php'; ?>
<?php endif; ?>
```

Nothing was rewritten — the markup moved, unchanged. This matters, and it is
provable: every page produces **byte-identical HTML** before and after.

| Page | Before | After | Its sections |
|---|---:|---:|---|
| `order-status.php` | 864 | **250** | one-order · my-orders · find-order |
| `kitchen.php` | 731 | **320** | order-board · bookings · staff-door |
| `order.php` | 652 | **288** | dishes · details · basket |
| `reserve.php` | 573 | **261** | booking-form · my-bookings |
| `index.php` | 421 | **114** | hero · about · hours |
| `account.php` | 396 | **96** | signed-in · sign-in-forms |
| `pay.php` | 370 | **133** | choose-method |
| `history.php` | 349 | **167** | past-orders · past-bookings · left-the-board |

What is left in a page file is mostly **logic, not markup** — the POST
handlers, the validation, the database calls. In `order.php` that is 232 lines
of handling to 56 of page frame. That logic belongs with the page it serves.

---

## 5. What is in `includes/`

These are loaded by `setup.php`, which every page runs first.

| File | Lines | Its one job |
|---|---:|---|
| `setup.php` | 50 | Timezone, session, then loads the four topic files |
| `functions.php` | 363 | `getPDO()`, `e()`, CSRF, the logo, breadcrumbs |
| `orders.php` | 512 | Move, cancel and delete an order or a booking |
| `auth.php` | 509 | Register, sign in, rate limiting, the staff door |
| `payments.php` | 319 | M-Pesa and cash settlement |
| `messaging.php` | 1028 | Email (its own SMTP client) and SMS |
| `session.php` | 114 | Keeps sessions in the database when there is no shared disk |
| `content.php` | 63 | Wording and opening hours, in one place |
| `header.php` / `footer.php` | 245 | The frame every customer page shares |

**Grouped by topic, not by type.** There is no `models/`, `views/`,
`controllers/`. Everything about taking money is in `payments.php`; everything
about an order's life is in `orders.php`. To change how cancelling works, you
open one file.

**86 functions, every one of them called.** No dead code.

---

## 6. How a request travels

Taking `/menu.php` as the example:

```
browser  ──▶  menu.php
                 │
                 ├─ require includes/setup.php
                 │     ├─ sets the timezone          (content.php)
                 │     ├─ starts the session         (session.php)
                 │     └─ loads messaging, payments, auth, orders
                 │
                 ├─ getPDO()  ──▶  MySQL  ──▶  the dishes
                 │
                 ├─ require includes/header.php      the page frame
                 ├─ prints the menu, escaping with e()
                 └─ require includes/footer.php
```

Three details in that chain are worth defending.

**The timezone is set before anything connects.** `getPDO()` tells the database
which offset to work in, using PHP's timezone at the moment it connects. With
database-backed sessions the first connection happens during setup — so if the
timezone were set afterwards, the connection would sit on UTC while PHP ran on
Nairobi time. Three hours of skew between `NOW()` and every date PHP computes.

**One connection per request.** `getPDO()` holds it in a `static`, so a page
that calls it twenty times still opens one connection.

**Prepared statements, with emulation off.** The placeholders go to MySQL
separately from the values, so a value can never be read as SQL.

---

## 7. The database

Thirteen tables. The ones that carry the work:

| Table | One row is |
|---|---|
| `categories` | A section of the menu |
| `menu_items` | One dish: name, description, price, photo |
| `restaurant_tables` | One table in the room: label, seats, area |
| `reservations` | One booking: table, date, time, who |
| `orders` | One order: reference, customer, total, status |
| `order_items` | One line of an order — with the price **at the time** |
| `users` | A customer account |
| `payments` | One attempt to take money |
| `order_events` | The audit trail: every status change, and who made it |
| `notifications` | Every email and SMS, sent or not |
| `login_attempts` | Failed sign-ins, for rate limiting |
| `sessions` | Sessions, when there is no shared disk |
| `order_access_codes` | The older way into an order, before accounts |

Seeded with **12 dishes**, **3 categories** and **19 tables** across five areas
of the room.

Two design decisions in that table worth more than they look:

**`order_items.unit_price` stores the price at the moment of ordering.** It is
not a link to today's price. If pilau goes up tomorrow, last week's order still
totals what the customer agreed to pay. An order is a record of an agreement,
not a live calculation.

**`order_events` is only ever added to.** Its foreign key is `ON DELETE SET
NULL`, not `CASCADE`, and each row carries the order reference. So when an
order is deleted its history survives — which is the only reason the kitchen
can still show that a deleted order ever existed.

---

## 8. Where each feature lives

A map for "where would I change X?":

| To change… | Open |
|---|---|
| The menu's look | `menu.php` and `.plate` in `css/style.css` |
| What a dish costs | the `menu_items` table — never the code |
| The ordering form | `includes/sections/order/details.php` |
| The basket and total | `includes/sections/order/basket.php` |
| How an order moves through stages | `order_next_statuses()` in `includes/orders.php` |
| The kitchen's tickets | `includes/sections/kitchen/order-board.php` |
| Booking rules | `reserve.php`, and `uniq_table_slot` in `schema.sql` |
| What a customer can do to a booking | `includes/sections/reserve/my-bookings.php` |
| Sign-in and registration | `includes/auth.php` + `includes/sections/account/` |
| Wording, hours, phone number | `includes/content.php` |
| Any colour or spacing | `css/style.css` — nothing else holds style |

---

## 9. What protects the data

| Attack | What stops it |
|---|---|
| SQL injection | Prepared statements everywhere, `EMULATE_PREPARES` off |
| XSS | `e()` on every printed value |
| Cross-site request forgery | A token in every form, compared with `hash_equals()` |
| Reading someone else's order | `may_view_order()` asks the database who owns it |
| Reading someone else's booking | `may_view_reservation()`, the same shape |
| Password theft from the database | `password_hash()` — there are no passwords to steal |
| Brute force | `login_attempts` locks out after 6 tries for 15 minutes |
| Double booking | A `UNIQUE` key on table + date + time |
| Two staff colliding | `SELECT … FOR UPDATE` row locks |
| Charging twice | A `UNIQUE` provider reference, so a repeat is ignored |
| Deleting a paid order | Refused outright — the row is the record of the money |

---

## 10. The numbers

| | |
|---|---:|
| Pages | 10 |
| Section files | 20, in 8 folders |
| Shared includes | 12 |
| Functions | 86, all called |
| Lines of PHP | ~8,000 |
| Lines of CSS | 6,339 |
| Lines of JavaScript | 683 |
| Database tables | 13 |
| Frameworks, build steps, dependencies | 0 |

---

## 11. Questions to expect

**"Why is the markup in separate files?"**
> A page used to be 864 lines and you could not see its shape. Each file is now
> one screen: the page holds the logic, the sections hold the HTML. The split
> points were the branches already in the code, so nothing was rewritten — the
> HTML is byte-identical before and after.

**"Why no framework?"**
> The brief was to show I can build this, not that I can configure something
> that builds it. Every decision here is mine and I can defend it. A framework
> would have produced the same site with the interesting parts hidden inside
> somebody else's code.

**"How do you know the refactoring did not break anything?"**
> Two checks. Every page's HTML was captured before and after and compared byte
> for byte — identical, with only the clock and the CSRF token masked. Then a
> sixteen-step test drives the real journeys over HTTP: register, sign in,
> order, cancel, delete, book, and get refused on a double-booking. All sixteen
> pass.

**"Why is `messaging.php` the longest file?"**
> It contains an SMTP client written from scratch — the socket, the handshake,
> AUTH, the MIME body. A project allowed to use Composer would be one
> `composer require` instead.

**"What would you do next?"**
> Split `messaging.php`, because an SMTP client and the rules about what to
> send are two different jobs. Add an automated test suite. And move the dish
> photos onto a CDN, because they are the slowest thing on the page.
