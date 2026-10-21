# Restaurant Menu Page

A restaurant website whose menu is loaded from a MySQL database by plain PHP. Visitors can read the menu by category, see the about text, hours and location, and send a table reservation.

**Stack:** HTML, CSS, plain PHP (PDO, no framework), MySQL / MariaDB.

**Team**

| Student | Role | Owns |
| --- | --- | --- |
| Sultan | Front-end | HTML templates, CSS, responsive design |
| Michael | Back-end | PHP, database connection, menu output, reservation form |
| Kevin | Database, content, DevOps | schema, seed data, written content, images, README |

---

## 1. Requirements

- PHP 8.1 or newer with the `pdo_mysql` extension
- MySQL or MariaDB
- Git
- A code editor (VS Code)

Check PHP and the extension:

```bash
php --version
php -m | grep -i pdo_mysql
```

---

## 2. Get the code

```bash
git clone https://github.com/Quantamania/restaurant-menu.git
cd restaurant-menu
```

(Replace `OWNER` with the GitHub username that owns the repo.)

---

## 3. Setup on Linux (Debian, Ubuntu, Kali)

### 3.1 Install what is missing

```bash
sudo apt update
sudo apt install php-cli php-mysql mariadb-server git
sudo systemctl start mariadb
```

### 3.2 Create the database and a user

Replace `choose-a-password` with your own password:

```bash
sudo mariadb -e "CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4; CREATE USER 'menu_user'@'localhost' IDENTIFIED BY 'choose-a-password'; GRANT ALL ON restaurant_menu.* TO 'menu_user'@'localhost';"
```

If it says the database already exists, run only the user part:

```bash
sudo mariadb -e "CREATE USER IF NOT EXISTS 'menu_user'@'localhost' IDENTIFIED BY 'choose-a-password'; GRANT ALL ON restaurant_menu.* TO 'menu_user'@'localhost';"
```

### 3.3 Create your config file

```bash
cp src/config.sample.php src/config.php
```

Open `src/config.php` and set:

```php
'host'   => 'localhost',
'dbname' => 'restaurant_menu',
'user'   => 'menu_user',
'pass'   => 'choose-a-password',
```

`src/config.php` is listed in `.gitignore`. **Never commit it.**

### 3.4 Import the tables and data

```bash
mariadb -u menu_user -p restaurant_menu < database/schema.sql
mariadb -u menu_user -p restaurant_menu < database/seed.sql
```

Run `seed.sql` **once**. Running it twice duplicates the menu items.

If your database was created **before** table booking existed, add the new
tables and columns with:

```bash
mariadb -u menu_user -p restaurant_menu < database/upgrade.sql
```

To start over instead:

```bash
sudo mariadb -e "DROP DATABASE restaurant_menu; CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4; GRANT ALL ON restaurant_menu.* TO 'menu_user'@'localhost';"
```

then import both files again.

### 3.5 Run the site

```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. Keep the terminal open while you work and stop the server with Ctrl+C.

### 3.6 Email, SMS and payments (optional)

**You can skip this entirely.** With no setup, the site still works end to
end: confirmation emails are written to `storage/mail/` as `.eml` files you
can open, text messages are recorded in the `notifications` table, and
M-Pesa payments are marked "pending" for someone to confirm. Nothing leaves
your machine.

Turn them on when you want messages to really go out.

```bash
cp src/secrets.example.php src/secrets.php
```

Then edit `src/secrets.php`. It lists every setting with a comment saying
what it is for; the blanks are the ones to fill.

**To send real email through Gmail**, set `driver` to `smtp` and fill in the
`smtp` block:

| Setting | Value |
|---|---|
| `host` | `smtp.gmail.com` |
| `port` | `587` |
| `encryption` | `tls` |
| `username` | your full Gmail address |
| `password` | a **Google App Password** — see below |
| `from_email` | the same Gmail address as `username` |

The password is **not** your normal Google password. Google refuses those
over SMTP. Make an App Password instead: myaccount.google.com → Security →
2-Step Verification (it has to be on) → App passwords. You get sixteen
letters; paste those in. It can be revoked on its own later without changing
the password you log in with.

Check it works — this sends one real email and prints what happened:

```bash
php -r 'require "src/setup.php"; $r = send_email("you@example.com", "Test from Maridadi Eat", "If you can read this, SMTP is working."); echo $r["ok"] ? "sent\n" : "failed: " . $r["error"] . "\n";'
```

If it fails, the message is the mail server's own words — `535 Username and
Password not accepted` means the App Password is wrong, and
`Could not reach smtp.gmail.com:587` means the network is blocking it. Every
attempt is also recorded in the `notifications` table:

```bash
mariadb -u menu_user -p restaurant_menu -e "SELECT channel, purpose, recipient, status, error FROM notifications ORDER BY id DESC LIMIT 10;"
```

**To send real SMS**, make a free account at africastalking.com, then set
`sms.driver` to `africastalking` and put the API key in. Leave `username` as
`sandbox` to start — sandbox messages cost nothing and only reach numbers you
have added to their simulator.

**To take real M-Pesa payments** you need a Daraja account from Safaricom and
a public HTTPS address for the callback, which a laptop does not have. Until
then leave `payments.driver` as `manual`: the customer is told to send the
money and someone confirms it at the till. Also put the restaurant's real
paybill or till number in `src/content.php` under `payment` — it is empty on
purpose, because a made-up number is somebody else's real account.

Never commit `src/secrets.php`. It is already in `.gitignore`.

### 3.7 Accounts

Ordering needs an account — there is nothing to configure, it just works.
A customer registers at **/account.php**, and from then on:

- the order form fills in their name, email and phone;
- **/order-status.php** lists every order on the account, so checking on food
  is just being signed in — no reference to keep and no code to wait for;
- registering with an email that already has orders against it **claims
  them**, so anybody who ordered before accounts existed keeps their history.

Passwords are stored as bcrypt hashes only. Eight wrong sign-in attempts locks
that account and that device out for fifteen minutes.

### 3.8 The kitchen screen

This is the staff page, at **<http://localhost:8000/kitchen.php>**. It is
where an order actually moves from *received* to *being cooked* to *ready* to
*collected*, and where the till marks a cash order as paid. Until somebody
presses those buttons, every order sits on "received" for ever and the
customer's tracking page never changes.

**On this machine it opens straight onto the board** — no password. That is
the `'require_password' => false` line in the `staff` block of
`src/secrets.php`, and the screen carries a standing amber warning while it
is off.

Leave it off while you are building. Turn it on before the site has a public
address, because the page lists every open order's name, phone number and
home address, and its buttons cancel orders and mark them paid:

```php
'require_password' => true,
```

A password must be set for that to work. On a fresh clone the setting
defaults to **true** and the screen refuses to open until one exists — it
does not fall back to letting anyone in.

```bash
php tools/staff-password.php
```

It asks for a password twice, hides it while you type, and prints a line like
`'password_hash' => '$2y$10$...'`. Paste that into the `staff` block of
`src/secrets.php`, then reload the page and sign in.

The password itself is never stored — only a hash of it. If you forget it, run
the tool again to set a new one. Six wrong tries locks that device out for
fifteen minutes.

A few things worth knowing about the screen:

- **The board refreshes itself every 45 seconds**, so a tablet can sit on a
  shelf. It needs no JavaScript at all.
- **Orders are listed soonest-first**, not newest-first, and a late one gets a
  red edge and says how many minutes late it is.
- **Open / Today / Recent** switch what you are looking at. "Open" is anything
  not finished, whatever day it is due — an order for tomorrow that nobody has
  started is exactly what must not be forgotten.
- **Marking an order "ready" texts the customer.** Cancelling it texts them
  too. Starting to cook does not — that would be noise, and every message
  costs money.
- **An order can only move forwards**, or be cancelled. There is no undo: a
  mis-tap must not be able to rewrite what happened.
- **A cancelled order can be deleted**, to clear the board. It asks twice, and
  it refuses if the order was paid — deleting that would destroy the only
  record of the money, and a cancelled-but-paid order is the one that still
  owes a refund.
- **It carries the logo and a Home / Kitchen trail**, but no site menu —
  the logo is the way back out.
- **It is not linked *to* from the site**, on purpose. Staff need to be told
  the address.

---

## 4. Setup on Windows (MySQL)

Linux uses MariaDB; on Windows you will use **MySQL**. You do not need to change
any PHP code for this. MySQL and MariaDB speak the same protocol, and the project
connects through PDO's `mysql` driver, which works with both. Only the install
steps and the command names differ.

> **Note:** XAMPP's control panel has a button labelled "MySQL", but recent XAMPP
> versions actually bundle MariaDB behind that label. This does not matter for
> this project — everything below works either way.

Pick **one** of the two routes:

- **Route A — XAMPP.** Easiest. One installer gives you PHP, MySQL and phpMyAdmin.
  Use this if you have no strong reason not to.
- **Route B — MySQL Installer + standalone PHP.** More setup, but you get the real
  MySQL Server and MySQL Workbench.

---

### Route A — XAMPP (recommended)

#### 4.1 Install and start the services

1. Download XAMPP from <https://www.apachefriends.org/> and install it to the
   default location, `C:\xampp`.
2. Open the **XAMPP Control Panel**.
3. Click **Start** next to **Apache**, then **Start** next to **MySQL**.
   Both should turn green. If one refuses to start, see the port notes in 4.13.

#### 4.2 Get the code into htdocs

Open **Command Prompt** (press Start, type `cmd`, press Enter):

```cmd
cd C:\xampp\htdocs
git clone https://github.com/Quantamania/restaurant-menu.git
cd restaurant-menu
```

#### 4.3 Create the database

The simplest way is phpMyAdmin:

1. Open <http://localhost/phpmyadmin>.
2. Click **New** in the left sidebar.
3. Database name: `restaurant_menu`
4. Collation: `utf8mb4_general_ci`
5. Click **Create**.

Prefer the command line? XAMPP's MySQL client lives in `C:\xampp\mysql\bin`:

```cmd
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4;"
```

XAMPP's `root` user has **no password** by default, so there is no `-p` flag above.

#### 4.4 Import the tables and the starting data

**Using phpMyAdmin:**

1. Click `restaurant_menu` in the left sidebar to select it.
2. Open the **Import** tab.
3. **Choose File** → `C:\xampp\htdocs\restaurant-menu\database\schema.sql` → **Go**.
4. Repeat the import for `database\seed.sql`.

Import `schema.sql` **first**, then `seed.sql`. Order matters — the seed data
cannot be inserted into tables that do not exist yet.

**Using the command line (Command Prompt):**

```cmd
cd C:\xampp\htdocs\restaurant-menu
C:\xampp\mysql\bin\mysql.exe -u root restaurant_menu < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root restaurant_menu < database\seed.sql
```

> **PowerShell users — this is the one that catches people out.** PowerShell does
> not support the `<` input redirect and will throw
> `The '<' operator is reserved for future use`. Use `Get-Content` instead:
>
> ```powershell
> Get-Content database\schema.sql | C:\xampp\mysql\bin\mysql.exe -u root restaurant_menu
> Get-Content database\seed.sql   | C:\xampp\mysql\bin\mysql.exe -u root restaurant_menu
> ```
>
> Or just use Command Prompt rather than PowerShell.

Run `seed.sql` **once only**. Running it a second time inserts every menu item
again and the site will show each dish twice. See 4.12 to start over.

If your database was created **before** table booking existed, add the new
tables and columns by importing `database\upgrade.sql` the same way (once).

#### 4.5 Create your config file

```cmd
copy src\config.sample.php src\config.php
```

Open `src\config.php` in VS Code. The defaults already match XAMPP, so for
Route A you normally change nothing:

```php
'host'   => 'localhost',
'dbname' => 'restaurant_menu',
'user'   => 'root',
'pass'   => '',
```

`src/config.php` is listed in `.gitignore`. **Never commit it.**

#### 4.6 Open the site

With Apache running, go to:

<http://localhost/restaurant-menu/public/>

#### 4.7 Alternative: run it without Apache

You can skip Apache entirely and use PHP's built-in server, exactly like the
Linux instructions. MySQL still needs to be running in the XAMPP panel:

```cmd
cd C:\xampp\htdocs\restaurant-menu
C:\xampp\php\php.exe -S localhost:8000 -t public
```

Then open <http://localhost:8000>. Stop the server with Ctrl+C.

This is often easier for debugging, because PHP errors print straight into the
terminal window instead of hiding in an Apache log.

---

### Route B — MySQL Installer + standalone PHP

Use this only if you want the genuine MySQL Server rather than XAMPP's bundle.

#### 4.8 Install MySQL

1. Download the **MySQL Installer for Windows** from
   <https://dev.mysql.com/downloads/installer/>.
2. Choose the **Developer Default** or **Server only** setup type.
3. When prompted, set a **root password** and write it down — unlike XAMPP, you
   will need it for every command and it goes into `src/config.php`.
4. Leave the port as the default `3306`.
5. Finish the wizard and let it start MySQL as a Windows service, so it comes
   back automatically after a reboot.

#### 4.9 Install PHP

1. Download the **VS16/VS17 x64 Thread Safe** ZIP from <https://windows.php.net/download/>.
2. Extract it to `C:\php`.
3. Copy `C:\php\php.ini-development` to `C:\php\php.ini`.
4. Open `C:\php\php.ini` and remove the leading `;` from this line so the MySQL
   driver loads:

   ```ini
   extension=pdo_mysql
   ```

5. Add `C:\php` to your PATH (see 4.11), then confirm in a **new** terminal:

   ```cmd
   php --version
   php -m | findstr pdo_mysql
   ```

   `php -m | findstr pdo_mysql` must print `pdo_mysql`. If it prints nothing, the
   extension line in `php.ini` is still commented out or `php.ini` is in the wrong
   folder.

#### 4.10 Create the database and import

```cmd
mysql -u root -p -e "CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4;"
cd C:\path\to\restaurant-menu
mysql -u root -p restaurant_menu < database\schema.sql
mysql -u root -p restaurant_menu < database\seed.sql
```

Each command will prompt for the root password you set in 4.8. The same
PowerShell `<` warning from 4.4 applies here.

Then copy the config and set your real password:

```cmd
copy src\config.sample.php src\config.php
```

```php
'host'   => 'localhost',
'dbname' => 'restaurant_menu',
'user'   => 'root',
'pass'   => 'the-password-you-set',
```

Run the site with:

```cmd
php -S localhost:8000 -t public
```

#### 4.11 Adding MySQL or PHP to your PATH

If `mysql` or `php` gives `'mysql' is not recognized as an internal or external
command`, Windows cannot find the program. Either type the full path every time
(`C:\xampp\mysql\bin\mysql.exe`), or add the folder to your PATH:

1. Press Start, type `environment variables`, open
   **Edit the system environment variables**.
2. Click **Environment Variables…**
3. Under **User variables**, select **Path** → **Edit** → **New**.
4. Add the folder that contains the `.exe`:
   - XAMPP MySQL: `C:\xampp\mysql\bin`
   - XAMPP PHP: `C:\xampp\php`
   - Standalone MySQL: `C:\Program Files\MySQL\MySQL Server 8.0\bin`
   - Standalone PHP: `C:\php`
5. Click OK on every dialog, then **close and reopen your terminal**. PATH changes
   do not apply to terminals that are already open.

#### 4.12 Resetting the database

If the menu shows duplicates, or you want a clean start:

```cmd
mysql -u root -p -e "DROP DATABASE restaurant_menu; CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4;"
```

Then import `schema.sql` and `seed.sql` again, once each. (With XAMPP, drop the
`-p` and use the full path to `mysql.exe`.)

#### 4.13 Windows troubleshooting

| Problem | Likely cause and fix |
| --- | --- |
| MySQL will not start in XAMPP | Port 3306 is already taken, usually by a MySQL service you installed separately. Stop that service in `services.msc`, or change XAMPP's port. |
| Apache will not start in XAMPP | Port 80 is taken, often by Skype, IIS, or VMware. Quit that program, or use the built-in PHP server from 4.7 instead. |
| `The '<' operator is reserved for future use` | You are in PowerShell. Use the `Get-Content` form in 4.4, or switch to Command Prompt. |
| `'mysql' is not recognized…` | Not on your PATH. See 4.11, or use the full path to `mysql.exe`. |
| `Access denied for user 'root'@'localhost'` | Wrong password in `src/config.php`. XAMPP uses an empty password; standalone MySQL uses the one you set during install. |
| `could not find driver` | `pdo_mysql` is not enabled. See step 4 of 4.9. |
| `Unknown database 'restaurant_menu'` | The database was never created, or the name in `config.php` does not match. |
| Menu items appear twice | `seed.sql` was imported more than once. Reset with 4.12. |

---

## 5. Check that it works

**The navigation is a narrow rail of icons** down the left. The emblem sits on
the white page at the top; below it a dark panel begins, with its right corners
rounded so it curves away from the edge of the page, and it stops short of the
bottom leaving white there too. Inside the panel: Menu, About, Hours, Order,
Track and Reserve, each a small inline SVG
over a short label, with two round buttons at the foot for the phone number and
opening hours. As a drawer on a phone the panel squares off and fills the
screen, since a floating shape makes no sense there. The icons are inline SVG, so there are no image files to ship or
go missing. `src/header.php` compares the running script against a `$navItems`
list and gives the matching item an accent-filled icon tile, white text and
`aria-current="page"`.

**The rail retracts.** A handle beside it slides the whole rail out of the way,
logo and all, so a page gets the full width. The choice is kept in
`localStorage` so it survives moving between pages. The retract rule is scoped to wide screens
only: below the breakpoint the rail is a drawer, and the rule would otherwise
outrank `.sidebar.is-open` and stop the ☰ button opening it.

Every page shares the same centred column, so the order page lines up with the
menu rather than running to the edges of the screen. The home page has three
anchors (Menu, About, Hours) but only Menu carries the marker, so exactly one
item is ever flagged.

**Check 1 — the home page renders.** You should see a dark green sidebar down
the left with the navigation in it, and beside it the menu on a cream board — "MARIDADI EAT / MENU" at
the top, then Breakfast, Mains and Drinks, each dish with dotted leaders
running out to its price.

**Check 2 — the database is reachable.** If you see "The menu is unavailable
right now" where the menu should be, PHP could not reach MySQL — go to section 6
or 4.13.

**Check 3 — the data imported.** Confirm 12 menu items across 3 categories:

```bash
mysql -u root -p restaurant_menu -e "SELECT c.name, COUNT(*) FROM menu_items i JOIN categories c ON c.id = i.category_id GROUP BY c.name;"
```

On Linux use `mariadb -u menu_user -p` instead. On XAMPP drop the `-p` and use
the full path, `C:\xampp\mysql\bin\mysql.exe`. You should see Breakfast, Drinks
and Mains with 4 each. If a category shows 8, `seed.sql` was imported twice.

To view saved reservations:

```bash
mysql -u root -p restaurant_menu -e "SELECT * FROM reservations;"
```

**Check 4 — the booking page works end to end.**

1. **Reserve** opens the booking page. Step 2 lists 19 tables across five
   areas, all marked *Free*.
2. Pick a party size of 6 — every smaller table greys out as *Too small* and
   cannot be clicked.
3. Click a free table (it gets an orange outline), fill in your details, and
   submit. You should be redirected and shown a green confirmation naming the
   table and the seating.
4. Book again for the **same date and seating** — that table is now greyed out
   and marked *Booked*, and clicking it does nothing. Change the seating and it
   is free again.
5. Set a few quantities in step 4 — the order total adds up as you type, and
   the confirmation repeats the total.
6. Submit bad data (one-letter name, invalid email, past date) — each bad field
   is outlined in red with its own message, and everything you typed is kept.

**Check 5 — ordering works, separately from booking.**

1. On the home page, click **Add to order** under any dish. The order page
   opens with that dish already at quantity 1.
2. Change some quantities — the total updates as you type.
3. Switch to **Delivery** — an address box appears and is then required.
4. Fill in name, email and phone, then **Place order**. You get an order
   number and the total.
5. The booking page has no dishes on it, and the order page has no tables.
   They are separate features.

**Check 6 — it works on a phone.** Resize the window below 960px: the sidebar
slides off-screen, a slim bar with a ☰ button appears, and every layout drops
to a single column. Tapping ☰ slides the sidebar back in over a dimmed page;
tapping the dimmed area, pressing Escape, or following a link closes it.

---

## 6. Troubleshooting

| Problem | Likely cause and fix |
| --- | --- |
| "The menu is unavailable right now" | The PHP could not reach the database. Check the error in the terminal running `php -S`, and check `src/config.php`. |
| `Access denied for user` | Wrong user or password in `src/config.php`. |
| `Unknown database` | The database was not created, or `dbname` in `config.php` is different. |
| `could not find driver` | Install the PHP MySQL extension (`sudo apt install php-mysql`) and restart. |
| "No menu items yet" | `seed.sql` was not imported. |
| Menu items appear twice | `seed.sql` was imported more than once. Reset the database (section 3.4). |
| Blank page | Read the PHP error in the terminal running `php -S`, or the Apache error log in XAMPP. |

---

## 7. Project structure

Fourteen files in three folders. That is the whole project.

```
restaurant-menu/
├── public/              # the only folder the browser can reach
│   ├── index.php        # HOME PAGE  - menu, about, hours
│   ├── reserve.php      # BOOKING PAGE - pick a free table and book it
│   ├── order.php        # ORDER PAGE - choose dishes, collection or delivery
│   ├── order-status.php # RECEIPT / TRACKING - look an order up by reference
│   ├── style.css        # every style for the whole site
│   ├── script.js        # every piece of JavaScript
│   └── images/          # photos
├── src/                 # the browser can NEVER reach these
│   ├── setup.php        # the first thing every page runs
│   ├── functions.php    # database connection + helper functions
│   ├── content.php      # restaurant name, about text, hours, address
│   ├── header.php       # top of every page (sidebar navigation)
│   ├── footer.php       # bottom of every page (contact details)
│   └── config.sample.php  # copy to config.php and add your password
├── database/
│   ├── schema.sql       # creates the tables
│   ├── seed.sql         # fills them with example dishes and 8 tables
│   └── upgrade.sql      # brings an older database up to date
├── .gitignore
└── README.md
```

### Why two folders?

`public/` is the **document root** — the only place the browser can load files
from. `src/` sits outside it, so even if someone types the address of
`src/config.php` into their browser, the web server will not hand it over. That
is why your database password lives in `src/` and never in `public/`.

### How a page fits together

Every page is built the same way. Open `public/index.php` and read top to
bottom:

```php
require __DIR__ . '/../src/setup.php';     // 1. start up
// ... PHP that fetches the menu from the database ...
require __DIR__ . '/../src/header.php';    // 2. print the top of the page
// ... the HTML a visitor sees ...
require __DIR__ . '/../src/footer.php';    // 3. print the bottom
```

The middle is ordinary HTML with small bits of PHP in it. `header.php` and
`footer.php` exist so the navigation and the contact details are written once
instead of twice.

`setup.php` is the one line that starts everything: it opens the visitor's
session, loads `functions.php` (which gives you `getPDO()` and `e()`), and
reads `content.php` into a variable called `$site`.

### Where do I put my code?

| I want to change… | Open this file |
| --- | --- |
| How the home page looks | `public/index.php` |
| The booking form, the table picker or the checks | `public/reserve.php` |
| The order page, or its checks | `public/order.php` |
| The receipt and tracking page | `public/order-status.php` |
| Delivery fee, minimum or kitchen notice | `src/content.php` (`ordering`) |
| The seatings, or the collection times | `src/content.php` |
| How many tables there are, their seats, or where they sit in the room | the `restaurant_tables` table |
| Any colour, font or spacing | `public/style.css` |
| The navigation links, or the sidebar | `src/header.php` (`$navItems`) |
| The address, phone or opening hours | `src/content.php` |
| A dish, its price or description | the database (not a file) |

`style.css` is one file split into five numbered sections, listed at the very
top. Section 1 holds the colours and fonts as variables — change
`--c-accent` there and every button, price and link changes with it.

### Two separate features

**Booking a table** (`reserve.php`) and **ordering food** (`order.php`) are
deliberately independent. You can book a table without ordering, order without
booking, or do both. They have their own pages, their own forms and their own
database tables — `reservations` for one, `orders` and `order_items` for the
other. Nothing links a row in one to a row in the other.

Every dish on the home page has an **Add to order** link. It opens
`order.php?item=7`, which starts that dish at a quantity of 1, highlights it
and jumps straight to it.

**The menu is the first thing on the page.** There is no hero banner — people
come for the food, so `index.php` opens onto a masthead across the top: the
word **MENU** hard against the far left, beside the sidebar, in **Anton** — a
heavy condensed grotesque — in the dark brown used by the sidebar and the
buttons, with the tagline over on the right, stacked one word per line. The words are
deliberately uneven — alternating big/small and heavy/light, two colours, and
each nudged a different distance from the right edge with a slight tilt — so
the column reads as hand-set rather than typed. The pattern repeats every six
words, so it works for a tagline of any length; below 680px the words flow
inline and the nudges and tilts are dropped. The E is 58% the height of its
neighbours and sits at the top, with the restaurant name tucked into the space
it leaves behind.

It is sized in container units (`42cqw`, capped at `21rem`) rather than `vw`,
because the sidebar takes 264px out of the window and `vw` knows nothing about
it.

The letterforms are squeezed to 84% of their width with `transform: scaleX`,
which narrows them and thins the stems — Anton has no condensed axis. A
negative bottom margin reclaims the empty descender space under the caps, so
the word can run large without shoving the menu down; the painted letters
still stop clear of the first course. On screens wide enough to have a sidebar
the masthead is pulled into the column's padding so the M starts right at the
sidebar edge; below that it keeps the normal page padding, or it would run off
the edge of a phone. The squeeze is anchored on the left
(`transform-origin: left`) so narrowing the letters does not pull them away
from that edge.

Below 680px the two halves stack and the tagline flows inline instead, so six
words do not become six tall lines. The `<h1>` carries `aria-label="Menu"`
and the letter spans are `aria-hidden`, so a screen reader hears "Menu" rather
than "M E Maridadi Eat N U".

Each course
gets a centred heading — the restaurant name, a small ornament, then the course
in large light type — and the dishes sit in two columns split by a vertical
rule. Every dish is a thumbnail, its name and description, and the price out on
the right.

**Dish photos come from the database.** `menu_items.image` holds a filename and
`menu_items.image_alt` the alt text; the file goes in `public/images/`:

```sql
UPDATE menu_items
SET image = 'nyama-choma.jpg', image_alt = 'Grilled goat with ugali'
WHERE name = 'Nyama Choma Plate';
```

Until a dish has one — and if the named file is missing — it shows a tile with
the dish's initial instead, so a row never renders as a broken image. Photos
are square and are cropped to fit, so any aspect ratio works.

**The order page is laid out like a till screen.** Down the left: a search box, then each section under a heading with a short
accent line beneath it and a link on the right — categories as round discs,
then the dishes as cards with a photo band, name, price and a − / + stepper. On the right a brown receipt card stays with you as
you scroll: an inset block at the top saying where the order is going, a
Collection/Delivery switch, the cart with a − n + control on each line, a torn edge, then sub total, delivery charge, total and
**Confirm order**. Only the cart scrolls: the totals and the button are pinned
to the bottom of the card, and the card is capped short of the viewport
height, so **Confirm order** is on screen from the moment the page loads —
even with every dish in the cart.

The cart's steppers are buttons, not fields — the real `qty[]` input lives on
the dish card, and the cart just drives it. Otherwise the same dish would be
submitted twice.

Searching and the chips only *hide* cards — they never remove them — so a dish
you added and then filtered away keeps its quantity and stays in the cart. The
steppers, the search and the chips are added by `script.js`; with JavaScript
off the plain number boxes remain, every dish is visible, and PHP renders the
cart and the totals on each submit, so the page still works.

**The booking page is a ticket.** `reserve.php` has a stub down the left —
shaped like a cloakroom ticket, with a torn perforation — that fills in as you
choose: date, seating, guests, then the table number in large type. It sticks
to the screen so the booking is always in view. On the right, the tables are
grouped by the part of the room they stand in (Banquette, Main floor, Bar,
Terrace, Private room) with a count of how many are still free in each.

### How table booking works

**You pick your table from a list of cards.** Step 2 of `reserve.php` shows
every table in the restaurant as a card with its number, how many it seats and
which part of the room it is in — Banquette, Main floor, Bar, Private room or
Terrace. Click the one you want.

A green **Free** card can be chosen. A card marked **Booked** is already taken
for that date and seating, and one marked **Too small** does not have enough
seats for your party; both are greyed out and cannot be clicked.

**The tables live in the database, not in the code.** The `restaurant_tables`
table holds a label (M1), how many seats it has, and which area it is in
(Banquette, Main floor, Bar, Private room, Terrace).

To add a table, insert a row — it appears on the booking page straight away:

```sql
INSERT INTO restaurant_tables (label, seats, area)
VALUES ('M7', 4, 'Main floor');
```

To retire one without deleting its history, switch it off instead:

```sql
UPDATE restaurant_tables SET is_active = 0 WHERE label = 'M7';
```

No PHP or CSS to edit either way.

**A booking is a table plus a date plus a seating.** The seatings are listed in
`src/content.php`. Booking T5 for Dinner on 31 December leaves T5 free at Lunch
that same day, and free at Dinner the next day.

**"Which tables are free?" is one SQL query.** `getTablesWithAvailability()` in
`src/functions.php` asks for every active table and LEFT JOINs the reservations
for that one slot. If the join finds a reservation, the table is taken:

```sql
SELECT t.id, t.label, t.seats, t.area, (r.id IS NOT NULL) AS is_taken
FROM restaurant_tables t
LEFT JOIN reservations r ON r.table_id = t.id
                        AND r.reservation_date = ?
                        AND r.reservation_time = ?
WHERE t.is_active = 1
```

**Two people cannot book the same table.** The picker greys out taken tables,
and `reserve.php` checks again before saving — but both of those can still lose
a race if two people click at the same instant. So the database has the final
say: `uniq_table_slot` in `schema.sql` makes a second booking for the same
table and slot physically impossible. The second INSERT fails, and `reserve.php`
turns that failure into "Table T5 has just been booked. Please choose another."

**The page works without JavaScript.** Changing the date or seating normally
refreshes the picker automatically; with JS off, a "Check availability" button
appears instead and does exactly the same thing.

### How ordering works

**Every order gets a reference** like `ZW-7K3QB2`, not a number like "order 8".
The customer quotes it on the phone, and `order-status.php` shows them where
their food is. Confusable characters (I, O, 0, 1) are left out of the code.

**Coming back later needs the phone number.** Anyone who has just ordered sees
their receipt straight away, because the reference is in their session. Anyone
else must give the reference *and* the phone number on the order — otherwise a
stranger guessing references could read names, addresses and phone numbers.

**The kitchen needs notice.** An order cannot be placed for a slot that has
already passed, or for one sooner than `lead_minutes` away (45 by default).
Slots that are too soon are greyed out in the dropdown, and checked again on
the server in case somebody edits the page.

**Delivery has a fee and a minimum**, both set in `src/content.php`. The fee is
added to the food subtotal; an order under the minimum is refused with the
amount still needed. `orders` stores `subtotal`, `delivery_fee` and `total`
separately so the money is always accountable.

**Times are worked out in the restaurant's timezone**, set once in
`src/content.php` and applied in `src/setup.php`. A server in another country
must still answer "has 1:30 pm gone?" using Nairobi's clock. The database
connection is pinned to the same zone, so `created_at` cannot drift.

**An order is saved whole, or not at all.** The order and all its dishes are
written inside a transaction, so an order never ends up half-saved with some
of its dishes missing.

**Prices are never trusted from the browser.** The form sends only quantities.
`buildOrderLines()` in `src/functions.php` looks up every price in the
database, and that price is copied into `order_items.unit_price`. Changing a
menu price next month does not rewrite what an old customer was charged. The
same function caps any quantity at `max_per_dish` and throws away negatives,
so a tampered form cannot order 9,999 plates.

**A dish that sells out mid-order is never dropped quietly.** If something
becomes unavailable between drawing the page and pressing the button, the
order is refused and the dish is named. Dropping it silently would charge the
customer less than they asked for and surprise them at the counter.

**Collection or delivery.** Choosing delivery reveals an address box, which is
then required. With JavaScript off the box is simply always visible, and PHP
still enforces the rule. A phone number is required for an order (the kitchen
rings you) but optional for a booking.

**Every order starts as `new`.** The `status` column moves through
`preparing`, `ready`, `completed` or `cancelled`, and the customer sees that
progress on `order-status.php`. Today the status is changed in the database by
hand:

```sql
UPDATE orders SET status = 'preparing' WHERE reference = 'ZW-7K3QB2';
```

A staff screen to do this with a click is the obvious next piece of work. It
is deliberately not built yet, because a page listing every customer's name,
address and phone number must sit behind a login first.

Two files are not in the list above, because Git never stores them:

- `src/config.php` holds your database password. You create it during setup
  (3.3 on Linux, 4.5 on Windows) and it is listed in `.gitignore`, so it never
  gets committed. Each teammate makes their own.
- `public/images/` is empty for now, and Git does not track empty folders, so
  it will not appear in a fresh clone. Create it, or it appears by itself once
  someone commits the first photo.

---

## 8. Team workflow (Git)

All the commands below are identical on Windows, Linux and macOS. On Windows,
run them in **Git Bash** or **Command Prompt** from inside the project folder.

**The one rule: never commit directly to `main`.** Everyone works on their own
branch and merges through a pull request.

### 8.1 One-time setup

Tell Git who you are, so commits are credited to the right person:

```bash
git config --global user.name "Your Name"
git config --global user.email "your-email@example.com"
```

### 8.2 Start work on your own branch

Always branch off an up-to-date `main`:

```bash
git checkout main
git pull origin main
git checkout -b frontend/sultan
```

`git checkout -b` creates the branch **and** switches to it. Use the branch name
that matches your role:

| Student | Branch name |
| --- | --- |
| Sultan | `frontend/sultan` |
| Michael | `backend/michael` |
| Kevin | `content/kevin` |

For a specific feature, name the branch after it instead — `frontend/menu-cards`,
`backend/reservation-validation`. Short, lowercase, hyphenated, no spaces.

Confirm which branch you are on at any time:

```bash
git branch
```

The branch with a `*` next to it is the one you are on. **Check this before you
start editing.** Accidentally working on `main` is the most common mistake.

### 8.3 Save your work: add, commit, push

This is the loop you will repeat all day.

**Step 1 — see what you changed:**

```bash
git status
```

Red files are changed but not staged. Green files are staged and ready to commit.

**Step 2 — stage your changes:**

```bash
git add .
```

The `.` means "everything in the current folder and below". If you only want
specific files, name them instead — this is safer:

```bash
git add public/style.css public/index.php
```

**Step 3 — commit, with a message describing what you did:**

```bash
git commit -m "Add menu category cards and responsive grid"
```

Write the message as an instruction: "Add the hero section", "Fix the price
alignment on mobile". Not "stuff", "changes", "update", or "asdf". The history is
how your teammates — and whoever marks this — see who built what.

**Step 4 — push to GitHub.**

The **first** time you push a new branch, you must tell Git where it goes:

```bash
git push -u origin frontend/sultan
```

`-u` links your local branch to the one on GitHub. After that first push, for the
rest of the life of that branch, you only need:

```bash
git push
```

### 8.4 The short version

Once you are on your branch, the everyday cycle is three commands:

```bash
git add .
git commit -m "Describe what you changed"
git push
```

Commit small and often — several times a day, not one giant commit at the end.
Small commits are easy to review and easy to undo if something breaks.

### 8.5 Keeping your branch up to date

While you work, teammates are merging their work into `main`. Pull those changes
into your branch regularly so you are not building on stale code:

```bash
git checkout main
git pull origin main
git checkout frontend/sultan
git merge main
```

If Git reports a **conflict**, it means you and a teammate changed the same lines.
Open the marked files, look for the `<<<<<<<`, `=======` and `>>>>>>>` markers,
delete the markers and keep the correct code, then:

```bash
git add .
git commit -m "Merge main into frontend/sultan"
```

Do not guess at conflicts in someone else's area — ask the person who wrote the
other side first.

### 8.6 Opening a pull request

When your piece is finished and pushed:

1. Go to the repo on GitHub.
2. A banner offers **Compare & pull request** for your branch — click it.
   (No banner? Open **Pull requests** → **New pull request**, set `base: main`
   and `compare: your-branch`.)
3. Give it a clear title and a sentence or two saying what you changed.
4. Click **Create pull request**.
5. Ask a teammate to review it. **Someone else merges it, not you.**

After it is merged, everyone updates their copy:

```bash
git checkout main
git pull origin main
```

Then start your next task from a fresh branch (back to 8.2).

### 8.7 Never commit these

`git add .` stages everything, which makes it easy to commit something you should
not. `.gitignore` already blocks the important ones, but check `git status`
before you commit:

- `src/config.php` — contains your database password
- `src/secrets.php` — contains your mail password, API keys and the kitchen password hash
- `storage/` — holds generated emails, which contain customers' details
- Any file with a password, API key, or personal data
- `.vscode/`, `.idea/`, `Thumbs.db`, `.DS_Store`

Committed something by mistake and have **not** pushed yet? Undo the last commit
but keep your file edits:

```bash
git reset --soft HEAD~1
```

If you already pushed a password, change that password — removing it from the
repo is not enough on its own.

### 8.8 Who depends on whom

- **Kevin** provides the schema and seed data, so the database exists before
  anyone queries it.
- **Michael** provides the PHP that outputs the HTML structure.
- **Sultan** styles that structure.

If you are blocked waiting on someone, say so early rather than inventing a
placeholder that has to be torn out later.

## 9. Security rules

- Use prepared statements for every query that includes user input.
- Escape every printed value with `e()` (which wraps `htmlspecialchars`).
- Validate all form input on the server.
- Never commit `src/config.php` or any password.
