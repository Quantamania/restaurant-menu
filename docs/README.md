# Maridadi Eat

A restaurant website in plain PHP and MySQL. Customers read the menu, pre-order
food, pay, track the order, and book a table. The kitchen has its own screen
where orders move through the stages.

No framework, no Composer, no npm, no build step. PHP, CSS and SQL only.

- **How it works, step by step:** [PRESENTATION.md](PRESENTATION.md)
- **Putting it online:** [DEPLOY.md](DEPLOY.md)

---

## What you need

| | |
|---|---|
| PHP | 8.1 or newer, with the `pdo_mysql` extension |
| Database | MySQL 8 or MariaDB 10.4+ |

Check PHP is ready:

```bash
php -v && php -m | grep pdo_mysql
```

---

## Setting it up

> Every command below is run from the **project root** — the folder that
> contains `index.php`, one level above this `docs/` folder.

**1. Create the database and a user for it.**

```bash
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS restaurant_menu CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'menu_user'@'localhost' IDENTIFIED BY 'your-password'; GRANT ALL ON restaurant_menu.* TO 'menu_user'@'localhost'; FLUSH PRIVILEGES;"
```

On Windows with XAMPP, create the database in phpMyAdmin instead and use the
`root` user.

**2. Load the tables, then the starting data.**

```bash
mariadb -u menu_user -p restaurant_menu < database/schema.sql
```

```bash
mariadb -u menu_user -p restaurant_menu < database/seed.sql
```

`schema.sql` creates the 13 tables. `seed.sql` fills in the menu, the
categories and the 19 tables in the dining room.

> Run `seed.sql` **only on a fresh database**. On a database that already has a
> menu it stops partway with `Duplicate entry`, and everything after that point
> — including the dish photos and the room's tables — is silently skipped.

**3. Tell the site how to reach the database.**

```bash
cp includes/config.example.php includes/config.php
```

Open `includes/config.php` and set the password you chose. This file is in
`.gitignore` and must never be committed.

**4. Start it.**

```bash
php -S localhost:8000
```

Then open <http://localhost:8000>.

On XAMPP, copy the folder into `htdocs` instead and open
`http://localhost/restaurant-menu/`.

---

## Checking it worked

| Open this | You should see |
|---|---|
| `/` | The home page, with dishes and photos |
| `/menu.php` | All 12 dishes, each with a price |
| `/reserve.php` | A date, a time, and tables to choose from |
| `/account.php` | Register, then place an order |
| `/kitchen.php` | The kitchen board |

If the menu says *"The menu is unavailable right now"*, PHP is running but the
database is not reachable — check the password in `includes/config.php`.

---

## Where everything lives

```
restaurant-menu/
│
├── index.php              ← the nine pages, at the top level
├── menu.php
├── order.php
├── pay.php
├── order-status.php
├── reserve.php
├── account.php
├── kitchen.php
├── payment-callback.php
│
├── includes/              ← shared PHP. Never opened directly by a visitor.
├── css/style.css          ← every style on the site
├── js/script.js           ← optional extras; the site works without it
├── images/                ← the 12 dish photos
├── database/              ← schema.sql (tables), seed.sql (starting data)
├── tools/                 ← command-line helper for the staff password
│
├── docs/                  ← these documents
├── vercel.json  .vercelignore  .gitignore
└── favicon.svg  favicon.ico  apple-touch-icon.png
```

**One page, one file, and the filename is the URL.** `/menu.php` runs
`menu.php`. There is no router and nothing rewriting addresses behind your
back. This is also why the project can be dropped straight into XAMPP's
`htdocs` and opened at `http://localhost/restaurant-menu/` with no
configuration — every path in the HTML is relative.

### `includes/` — the shared parts

| File | Lines | What it does |
|---|---:|---|
| `setup.php` | 32 | Runs first on every page: cookie rules, session, loads the rest |
| `functions.php` | 340 | `getPDO()`, `e()`, the CSRF helpers, the logo, breadcrumbs |
| `orders.php` | 311 | The life of an order: move it, cancel it, delete it, list it |
| `auth.php` | 485 | Customer accounts, and the staff door to the kitchen |
| `payments.php` | 319 | M-Pesa and cash settlement |
| `messaging.php` | 1028 | Email (its own SMTP client) and SMS |
| `session.php` | 91 | Keeps sessions in the database when there is no shared disk |
| `content.php` | 63 | Wording, phone number, opening hours — all in one place |
| `header.php` | 172 | The top of every page |
| `footer.php` | 73 | The bottom of every page |
| `config.php` | 68 | **The database password. Gitignored. Never commit it.** |
| `config.example.php` | 72 | The shareable template to copy |

`includes/.htaccess` denies direct web access to this folder on Apache, and
`vercel.json` returns 404 for `/includes/` when hosted. Nothing in here is
meant to be opened as a URL — the pages `require` it.

### The other folders

- **`css/`** and **`js/`** — one file each. `style.css` is the whole design
  system; `script.js` only adds comfort, never function.
- **`images/`** — dish photos, named after the dish
  (`chicken-pilau.jpg`). `menu_items.image` stores just the filename.
- **`database/`** — `schema.sql` creates the 13 tables, `seed.sql` fills in the
  menu and the dining room. Run in that order, on a fresh database.
- **`tools/`** — command-line helpers, not part of the website:
  `staff-password.php` hashes a kitchen password for `config.php`,
  `load-remote-db.php` loads the schema into a hosted database, and
  `diagnose-remote.php` reports why a hosted database is refusing writes.
- **`storage/`** and **`assets/`** — written output and original artwork. Both
  gitignored; neither is needed to run the site.

---

## Two rules

**Never commit `includes/config.php`.** It holds the database password.
`includes/config.example.php` is the version that is safe to share.

**Never put a value from a form straight into SQL.** Every query in this
project uses a prepared statement with `?` placeholders. There are no
exceptions, and there is no reason to add one.
