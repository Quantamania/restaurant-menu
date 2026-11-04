# Deploying to Vercel

## Can this project be hosted on Vercel? — the short answer

**Yes, with one external dependency: a MySQL database. Vercel does not provide
one.** That is the only real blocker, and no amount of configuration removes
it.

Here is exactly what has been verified, and what has not, so you are not
guessing on the day:

| | Status |
|---|---|
| `vercel.json` validates against Vercel's official JSON schema | **verified** |
| The PHP runtime matches: `vercel-php@0.8.0` is PHP 8.4, same as local | **verified** |
| `includes/` reaches the functions — the runtime uploads every file and you exclude with `.vercelignore` | **verified from the runtime's own docs** |
| Nothing writes to disk except the `log` mail driver, which fails softly | **verified** |
| Every setting can come from environment variables | **verified** |
| Sessions survive across containers | **verified locally** with two processes on different save paths |
| The actual deployment builds and serves | **not verified** — needs a Vercel login |

The last row is the honest gap. Verifying it means running `vercel build`,
which requires signing in to your account, so it is yours to run, not mine.
Everything checkable without your credentials has been checked.

**Two things to watch on the first deploy**, both visible immediately:

- *PHP source shown as text instead of running* — the `functions` glob did not
  match. The official `vercel-php` examples put PHP in an `api/` folder; this
  project uses a root-level `"*.php"` glob instead, which is valid per the
  schema but is not the documented example. If this happens, see
  "If the root glob does not work" at the end.
- *A blank page or 500* — almost always the database, not the code. Check the
  credentials and whether your provider allows connections from Vercel's IPs.

---

## Before you start: you need a MySQL database

**Vercel does not provide MySQL.** This is the one hard requirement — the
site cannot run without a database reachable from the internet.

Free tiers that work:

| Service | Notes |
|---|---|
| [Aiven](https://aiven.io) | Free MySQL plan, straightforward |
| [Railway](https://railway.app) | Free starter MySQL |
| [TiDB Cloud](https://tidbcloud.com) | MySQL-compatible, generous free tier |
| [PlanetScale](https://planetscale.com) | MySQL, no free tier since 2024 |

Whichever you pick, you end up with four values — **host, database name,
user, password**. Keep them to hand.

---

## Step 1 — Load the schema into that database

From your laptop, pointing at the hosted database rather than the local one:

```bash
cd /home/gaitho/restaurant-menu && mariadb -h YOUR_HOST -P 3306 -u YOUR_USER -p YOUR_DBNAME < database/schema.sql
```

Then the menu and the tables in the room:

```bash
cd /home/gaitho/restaurant-menu && mariadb -h YOUR_HOST -P 3306 -u YOUR_USER -p YOUR_DBNAME < database/seed.sql
```

Check it took — you should see 13 tables:

```bash
mariadb -h YOUR_HOST -P 3306 -u YOUR_USER -p YOUR_DBNAME -e "SHOW TABLES;"
```

> `sessions` **must** be among them. Without it nothing on the site works —
> see "Why sessions matter" below.

---

## Step 2 — Push the code to GitHub

The remote is already set to `Quantamania/restaurant-menu`.

```bash
cd /home/gaitho/restaurant-menu && git add -A && git commit -m "Add Vercel deployment config and database sessions" && git push
```

`includes/config.php` is gitignored, so your database password is **not** pushed.
That is deliberate — on Vercel the settings come from environment variables.

---

## Step 3 — Import the project in Vercel

1. Go to <https://vercel.com/new>
2. Import `Quantamania/restaurant-menu`
3. **Framework Preset: Other.** Leave Build Command and Output Directory
   empty — `vercel.json` handles everything.
4. Before clicking Deploy, add the environment variables below.

### Environment variables

Required — the site will not start without these four:

| Name | Value |
|---|---|
| `DB_HOST` | your database host |
| `DB_NAME` | your database name |
| `DB_USER` | your database user |
| `DB_PASS` | your database password |

If your host uses a non-standard port, put it in `DB_HOST` like
`myhost.aivencloud.com;port=24067`.

Worth adding:

| Name | Value | Why |
|---|---|---|
| `BASE_URL` | `https://your-app.vercel.app` | Tracking links inside emails |
| `STAFF_PASSWORD_HASH` | output of `php tools/staff-password.php` | The kitchen screen refuses to open without it |

`SESSION_DRIVER=database` is already set in `vercel.json`, so you do not need
to add it.

Only if you want real email and SMS:

```
MAIL_DRIVER=smtp   SMTP_HOST   SMTP_USERNAME   SMTP_PASSWORD   MAIL_FROM
SMS_DRIVER=africastalking   AT_USERNAME   AT_API_KEY
```

Leave them out and the site still works end to end — messages are recorded
in the `notifications` table instead of being sent.

---

## Step 4 — Deploy, then check

Click Deploy. When it finishes, work through this list in order. Each one
tests a different layer:

| Check | What it proves |
|---|---|
| The home page loads with dishes on it | PHP runs **and** the database is connected |
| `/menu.php` shows all 12 dishes | The seed data loaded |
| Register an account at `/account.php` | **Sessions and CSRF work** — the big one |
| Place an order | The whole write path, in a transaction |
| `/order-status.php` lists it | Session survived across requests |
| `/kitchen.php` | The staff screen (needs `STAFF_PASSWORD_HASH`) |

If registering fails with *"That form had expired"*, the `sessions` table is
missing. Re-run Step 1.

---

## Why sessions matter here

PHP normally keeps sessions as files in a temp directory. Serverless
containers do not share one, so the next request cannot read what the last
one wrote.

On this site that would not merely log you out — **every form carries a CSRF
token held in the session**, so ordering, booking, paying and the kitchen's
buttons would all come back *"your session expired"*. The site would look
perfect and do nothing.

`includes/session.php` keeps sessions in the `sessions` table instead. It switches
on automatically when `VERCEL` is set, and falls back to files locally.

This was tested before deploying, by running two PHP processes with
different `session.save_path` values — the closest stand-in for two
containers:

| | files | database |
|---|---|---|
| Second container sees the signed-in session | **no** | yes |
| CSRF token from container A accepted by B | — | **yes** |

---

## What will not work on Vercel

Honest list.

**`.eml` files in `storage/mail/`.** The filesystem is read-only. With
`MAIL_DRIVER=log` the message is still recorded in the `notifications`
table — you just cannot open it as a file. Use SMTP in production.

**The M-Pesa callback needs a public HTTPS URL** — which Vercel gives you, so
set `MPESA_CALLBACK_URL=https://your-app.vercel.app/payment-callback.php`
once you have real Daraja credentials.

**`vercel-php` is a community runtime**, not an official Vercel one. It is
widely used and actively maintained, but it is not covered by Vercel support.

---

## If something breaks

```bash
npx vercel logs YOUR_DEPLOYMENT_URL
```

The app writes real errors through `error_log()`, which lands in Vercel's
function logs. Visitors only ever see one plain sentence; the detail is in
the logs.

Most likely causes, in order:

1. **A blank page or 500** — database credentials wrong, or the host does not
   allow connections from Vercel's IPs. Check your provider's allow-list.
2. **"That form had expired"** on every form — the `sessions` table is
   missing.
3. **"The menu is unavailable right now"** — PHP is fine, the database is not
   reachable. The page degrades on purpose rather than crashing.
4. **PHP source shown as text** — the Framework Preset was not set to
   *Other*, so Vercel treated the project as a static folder. `vercel.json`
   sets `"framework": null` to prevent this, but check the project settings.

---

## The layout Vercel sees

```
index.php  menu.php  order.php  pay.php  order-status.php
reserve.php  account.php  kitchen.php  payment-callback.php
                        ← each becomes its own serverless function
includes/               ← uploaded, but 404 over the web
css/  js/  images/      ← served as static files
database/  tools/  *.md ← excluded by .vercelignore, never uploaded
```

`vercel.json` says:

```json
"functions": { "*.php": { "runtime": "vercel-php@0.8.0" } },
"routes": [
  { "src": "/includes/(.*)", "status": 404 },
  { "src": "/",              "dest": "/index.php" },
  { "src": "/([a-z0-9-]+)$", "dest": "/$1.php" }
]
```

Three rules, in order: hide `includes/`, send `/` to the home page, and let
`/menu` work as well as `/menu.php`. Anything else — `css/style.css`,
`images/chicken-pilau.jpg` — matches no rule and is served as a static file.

`includes/config.php` is gitignored, so it is never uploaded. On Vercel the
database settings come from environment variables instead, which is why
`getPDO()` reads `getenv('DB_HOST')` before the config file.

---

## If the root glob does not work

If the first deploy serves PHP as plain text, the `"*.php"` glob did not match
and the fix is the documented layout. You do **not** need to restructure the
project — add a thin entry point:

1. Create `api/index.php` containing one line:

   ```php
   <?php require __DIR__ . '/../' . (basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
   ```

2. Change `vercel.json` to the documented shape:

   ```json
   "functions": { "api/*.php": { "runtime": "vercel-php@0.8.0" } },
   "routes": [
     { "src": "/includes/(.*)", "status": 404 },
     { "src": "/(.*\\.(css|js|jpg|png|svg|ico))$", "dest": "/$1" },
     { "src": "/(.*)", "dest": "/api/index.php" }
   ]
   ```

The nine pages stay exactly where they are, so nothing a lecturer reads
changes — only the hosting plumbing.
