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

Run `seed.sql` **once**. Running it twice duplicates the menu items. To start over:

```bash
sudo mariadb -e "DROP DATABASE restaurant_menu; CREATE DATABASE restaurant_menu CHARACTER SET utf8mb4; GRANT ALL ON restaurant_menu.* TO 'menu_user'@'localhost';"
```

then import both files again.

### 3.5 Run the site

```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. Keep the terminal open while you work and stop the server with Ctrl+C.

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

> **Expect a blank page right now.** The front-end markup has deliberately been
> removed so it can be rebuilt from scratch — see `frontend-reference/` for the
> old design. A white page is **not** a failed setup. Use the checks below to
> confirm your database is wired up correctly.

**Check 1 — the page loads without a PHP error.** Open the site. You should get a
blank page, *not* a 500 error and *not* a wall of PHP warnings. Right-click →
**View page source**: you should see the `<head>` with the title
`Home | Ziwa Café`, plus HTML comments describing what to build.

**Check 2 — the database is actually reachable.** If the page source contains the
text "The menu is unavailable right now", PHP could not reach MySQL — go to
section 6 or 4.13. If that message is absent, the menu query ran fine.

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

**Once the front-end is rebuilt**, these are the real acceptance checks:

1. The home page shows the hero, 3 menu categories with 4 items each, About, and Hours.
2. **Reserve** opens the form. Submit valid data and you should see a confirmation message.
3. Submit bad data (empty name, invalid email, past date) and you should see error messages with your input kept.

> **Note for whoever builds the reservation form:** it must include
> `<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">`. Nothing else
> seeds the CSRF token now, so without it every submission is silently rejected
> as an expired session.

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

```
restaurant-menu/
├── public/              # the website (document root)
│   ├── index.php        # home page: loads menu data, markup to be built
│   ├── reserve.php      # reservation validation and handler, markup to be built
│   ├── css/style.css    # empty, to be built
│   ├── js/
│   └── images/
├── src/
│   ├── bootstrap.php    # session, database, helpers, site content
│   ├── db.php           # PDO connection
│   ├── helpers.php      # e() escaping and CSRF helpers
│   ├── site.php         # page text, address, opening hours
│   ├── config.sample.php  # copy to config.php (not committed)
│   └── includes/        # header.php, footer.php
├── frontend-reference/  # snapshot of the starter design, not used at runtime
├── database/
│   ├── schema.sql       # tables
│   └── seed.sql         # starting data
├── .gitignore
└── README.md
```

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
git add public/css/style.css public/index.php
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
