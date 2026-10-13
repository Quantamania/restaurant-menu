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

## 4. Setup on Windows (XAMPP)

1. Install XAMPP and start **Apache** and **MySQL** in the XAMPP control panel.
2. Clone the repo into `C:\xampp\htdocs\`:

```
   cd C:\xampp\htdocs
   git clone https://github.com/Quantamania/restaurant-menu.git
```

1. Open <http://localhost/phpmyadmin>, click **New**, and create a database named `restaurant_menu` (collation `utf8mb4_general_ci`).
2. Select that database, go to **Import**, and import `database/schema.sql`, then `database/seed.sql` (once only).
3. Copy `src/config.sample.php` to `src/config.php`. The default values (`root` with an empty password) already match XAMPP, so no edits are needed.
4. Open <http://localhost/restaurant-menu/public/>

---

## 5. Check that it works

1. The home page shows the hero, 3 menu categories with 4 items each, About, and Hours.
2. **Reserve** opens the form. Submit valid data and you should see a green confirmation message.
3. Submit bad data (empty name, invalid email, past date) and you should see error messages with your input kept.

To view saved reservations:

```bash
mariadb -u menu_user -p restaurant_menu -e "SELECT * FROM reservations;"
```

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

## 8. Team workflow

- Never push straight to `main`. Everyone works on their own branch:

```bash
  git checkout main && git pull
  git checkout -b frontend/sultan      # or backend/michael, content/kevin
```

- Commit small and often, with clear messages, for example `Add category headings to menu`. The commit history shows who did which part.
- Push your branch and open a **pull request** on GitHub. A teammate reviews it before it is merged into `main`.
- After a merge, everyone runs `git checkout main && git pull`.
- Dependencies: Kevin provides the schema and seed data, Michael provides the HTML structure the PHP outputs, and Sultan styles that structure.

## 9. Security rules

- Use prepared statements for every query that includes user input.
- Escape every printed value with `e()` (which wraps `htmlspecialchars`).
- Validate all form input on the server.
- Never commit `src/config.php` or any password.
