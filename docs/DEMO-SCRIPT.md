# Maridadi Eat — presentation script

What to do, in order, and what to say while doing it. Roughly 12–15 minutes.

The spine of the demo is **one order's whole life**: a customer places it, the
kitchen cooks it, the customer cancels it, and the kitchen is told. Everything
technical gets mentioned at the moment it actually happens on screen, rather
than as a lecture up front.

> **Companion documents:** [PRESENTATION.md](PRESENTATION.md) explains how the
> code works if you are asked to go deeper. This file is only the running
> order.

---

## Before you start — five minutes of setup

Do all of this *before* anyone is watching.

**1. Start the database** (it survives a reboot, but check):

```bash
sudo systemctl status mariadb --no-pager
```

**2. Start the web server** and leave the terminal open:

```bash
php -S localhost:8000
```

**3. Check the five pages load.** Open each one once, so the browser has them
cached and nothing stalls on the day:

`/` · `/menu.php` · `/reserve.php` · `/account.php` · `/kitchen.php`

**4. Have two browser tabs ready:**

| Tab | Open at | Why |
|---|---|---|
| **1 — Customer** | `http://localhost:8000` | Everything the customer does |
| **2 — Kitchen** | `http://localhost:8000/kitchen.php` | Switch to it to show orders arriving |

Two tabs is the whole trick of this demo. Place an order in tab 1, switch to
tab 2, and the order is already there. That single moment carries the point
better than any explanation.

**5. Know your numbers.** The database starts empty on purpose — no fake
orders. What is already in it:

- **12 dishes** across 3 categories
- **19 tables** across 5 areas of the room
- **0 orders, 0 bookings** — everything you show, you create live

**6. Pick your collection slot.** The order form only offers these, and the
kitchen needs 45 minutes' notice, so pick one at least an hour ahead:

> 11:30 am · 12:30 pm · 1:30 pm · 5:30 pm · 6:30 pm · 7:30 pm · 8:30 pm

**7. Decide your account.** Registering live is the better demo. Use a fresh
email — the `email` column is `UNIQUE`, so your own address will be rejected if
you have already signed up with it.

---

## 1. Open on the problem, not the code — 45 seconds

> "Maridadi Eat is a restaurant site. A customer can read the menu, order food
> ahead and pay, or book a table. The kitchen has its own screen where orders
> move through the stages.
>
> The thing I want to show is that those two sides are the same system. What a
> customer does appears in the kitchen immediately, and what the kitchen does
> appears back on the customer's page."

**Do not open the editor yet.** Show the working site first; the code is more
convincing once they have seen what it produces.

---

## 2. The menu comes from the database — 1 minute

**Tab 1 → `/menu.php`**

Scroll through the dishes.

> "Nothing on this page is written into the HTML. It is twelve rows from a
> MySQL table, grouped by category, with the price and the photo filename
> coming from the database."

**If you want to prove it**, in a spare terminal:

```bash
mariadb -u menu_user -p restaurant_menu -e "SELECT name, price FROM menu_items ORDER BY price DESC LIMIT 4;"
```

> "One query gets the dishes and their category names together. Looping over
> categories and querying inside the loop would have worked too — and would
> have been one query per category instead of one for the lot."

---

## 3. Create an account — 1 minute

**Tab 1 → `/account.php` → Create an account**

Fill it in and submit. You land on your (empty) orders page.

> "The password is never stored. What goes into the database is a bcrypt hash —
> sixty characters, with its own random salt inside it."

**The line worth saying out loud:**

> "Two users with the same password get different rows, because each hash is
> salted separately. And one hash takes about 200 milliseconds to compute, so a
> million guesses would take roughly 60 hours. That slowness is deliberate."

If they ask to see it:

```bash
mariadb -u menu_user -p restaurant_menu -e "SELECT name, email, LEFT(password_hash,10) AS hash_start, LENGTH(password_hash) AS len FROM users;"
```

---

## 4. Place an order — 2 minutes

**Tab 1 → `/order.php`**

1. Add **2 × Mandazi & Masala Chai** and **1 × Chicken Pilau**.
2. Watch the total update as you click — that is the JavaScript.
3. Choose **Collection**, today's date, and your chosen slot.
4. Press **Continue to payment**.

You are redirected to the payment page with a reference like `MD-7K3QB2`.

**Three things to say while this happens:**

> "The total you saw updating is JavaScript, but the total that gets saved is
> recalculated in PHP from the database prices. If I edited the page to make
> pilau cost one shilling, the server would ignore me."

> "The reference has no I, O, zero or one in it, so it can be read out over a
> phone without anyone mishearing it."

> "The order and its dishes are written in one transaction. If it failed
> halfway, there would be no half-order left behind — it arrives whole or not
> at all."

---

## 5. Pay — 45 seconds

**On the payment page**, point out the two routes: M-Pesa, or pay on
collection.

> "No real M-Pesa credentials are configured, so on localhost there is a
> development button that simulates a successful payment. In production the
> same code path takes a Daraja callback."

Press **Simulate a successful payment**. You land on the tracking page, now
marked paid.

> "The payment has a unique provider reference in the database, so if the
> gateway sends the same confirmation twice, the second one is ignored rather
> than charging twice."

---

## 6. The moment that matters — switch to the kitchen — 1 minute

**Tab 2 → reload `/kitchen.php`**

The order is on the board.

> "I did not refresh anything on the kitchen side by hand before now — it reads
> the same table the order was written to. And this screen refreshes itself
> every 45 seconds with a meta refresh, not JavaScript, so a tablet on a
> kitchen shelf stays current even with scripting switched off."

Point out on the ticket: the reference, the collection time, the paid badge,
the dish photos, the quantities.

---

## 7. Cook it — 1 minute

Press the buttons in order and let the colours do the talking:

| Press | The ticket becomes |
|---|---|
| **Start cooking** | amber |
| **Mark ready** | teal |
| **Mark collected** | leaves the board |

> "The stages are defined in one function, and both the buttons and the code
> that writes the change read from it. So an order cannot jump from new
> straight to completed — there is no path that allows it."

**If asked about two staff pressing at once:**

> "The order row is locked for the length of the move with SELECT … FOR UPDATE.
> The second request waits, re-reads the status, and finds the move no longer
> allowed. Without the lock they would both read 'new' and one change would
> quietly overwrite the other."

Before moving on, **place a second order** (tab 1, any dish, same slot) and
leave it on the board — you need a live order for the next part.

---

## 8. Cancel it, and watch the kitchen find out — 2 minutes

This is the strongest part of the demo. Do it slowly.

**Tab 1 → `/order-status.php`** → open the live order → **Cancel this order**
→ confirm.

**Tab 2 → reload `/kitchen.php`**

Two things changed:

1. The ticket is **gone from the Open board**.
2. A red strip at the top says **"1 order has left the board in the last 3
   hours"**, naming the reference, who cancelled it, and when.

> "This strip exists because of a real problem. A cancelled order simply
> disappears from the live board, which means the kitchen could carry on
> cooking something the customer already called off. Now it is told."

Click the **Cancelled** tab to show the full list.

**Then delete it.** Back in tab 1, on the cancelled order, press
**Delete this order** → confirm.

**Tab 2 → reload.** The strip now reads **"deleted for good"**, in a different
style from merely cancelled.

> "The order row really is gone from the database. The history survives,
> because the foreign key is ON DELETE SET NULL rather than CASCADE, and each
> event stores the reference. So the kitchen can still see that an order
> existed and was deleted, and by whom."

**The one to have ready, because it is the obvious question:**

> "A **paid** order can never be deleted — by the customer or the kitchen. That
> row is the only record that money changed hands. The code checks both the
> order and the payments table and refuses. A paid order gets refunded and
> kept."

---

## 9. Book a table — 1 minute 30

**Tab 1 → `/reserve.php`**

Pick a date, pick **Dinner — 7:30 pm**, choose a table, and book.

Booking slots are: **12:00 · 1:30 · 6:00 · 7:30 · 9:00**.

Now **try to book the same table at the same time again.** It is refused:
*"Table M1 has just been booked. Please choose another."*

> "The obvious way to stop a double booking is to check first and then insert.
> That is wrong — two people clicking at the same instant both pass the check,
> and you get two bookings.
>
> So the rule lives in the database: a unique key across table, date and time.
> The second insert cannot succeed. MySQL refuses it, the PHP catches that one
> error code, and turns it into that sentence.
>
> The friendly check still runs, because it gives a better message most of the
> time. But the thing that actually guarantees it is the constraint, because
> only the database can make checking and writing a single step."

**Tab 2 → `/kitchen.php?view=bookings`** — the booking is there, with its
table and party size.

---

## 10. The hidden staff shortcut — 30 seconds

**Tab 1 → the home page.** Let the hero plate finish its entrance animation,
then **click the round plate three times quickly.** You land on the kitchen
board.

> "The staff use a counter tablet, so this saves them typing a URL. There is no
> button for it anywhere — a customer has no reason to see one.
>
> I should be clear that this is obscurity, not security. What actually
> protects the board is the staff password; this only saves the typing."

---

## 11. The file structure — 1 minute

*Now* open the editor.

```
index.php  menu.php  order.php  pay.php  order-status.php
reserve.php  account.php  kitchen.php  payment-callback.php
includes/   css/   js/   images/   database/
```

> "Nine pages at the top level, and the shared code in `includes/`. Opening
> slash-menu-dot-php runs menu.php — the URL is the filename. There is no
> router to explain and nothing to misconfigure.
>
> No framework, no Composer, no npm, no build step. Every file here is one I
> wrote and can explain."

Open `includes/` and show the twelve files.

> "Grouped by topic, not by type. There is no models, views, controllers.
> Everything about taking money is in payments.php; everything about an order's
> life is in orders.php. To change how cancelling works, I open one file."

---

## 12. Close on the two defences — 45 seconds

> "Two things run through the whole project.
>
> Every query is a prepared statement with placeholders, and emulation is
> turned off — so the values go to MySQL separately from the SQL and can never
> be read as SQL.
>
> And every value printed to a page goes through one escaping function. The
> first stops bad input getting in; the second stops it doing harm coming out.
> Both are needed — neither covers the other."

Then stop. Let them ask.

---

## Questions you should expect

**"Why no framework?"**
> "The brief was to show I can build this, not that I can configure something
> that builds it. Every decision here is mine and I can defend it."

**"What is the longest file and why?"**
> "`messaging.php`, at about a thousand lines, because it contains an SMTP
> client written from scratch — the socket, the handshake, AUTH, the MIME body.
> With Composer that would have been one line."

**"How do you know it works?"**
> "Every flow was tested end to end over real HTTP — register, sign in, order,
> pay, cancel, delete, book, double-book. And when I restructured the project,
> I captured every page's HTML before and after and diffed them: the only
> differences were the two asset paths I meant to change."

**"What would you do next?"**
> "Split `messaging.php`, because an SMTP client and the rules about what to
> send are two different jobs. Add an automated test suite — testing so far has
> been by hand. And host the photos somewhere else, because they are the
> slowest thing on the page."

**"What went wrong along the way?"**
> "The one worth telling: the dish photos vanished, and the cause was a
> semicolon *inside a comment* in the seed file. The import split the file on
> semicolons, cut that comment in half, and silently skipped everything after
> it — which happened to be all twelve photo updates and the dining room's
> tables. The lesson was that an import which stops partway is worse than one
> that fails loudly."

---

## If something goes wrong

| Symptom | Cause | Fix |
|---|---|---|
| Raw `<?php` in the browser | Server started with the old `-t public` flag | Restart with `php -S localhost:8000` |
| "Address already in use" | A server is already running | Use it, or `pkill -f 'php -S'` first |
| "The menu is unavailable right now" | Database unreachable | `sudo systemctl start mariadb` |
| Page loads but unstyled | Stale cache | `Ctrl+Shift+R` |
| Triple-click does nothing | Hero animation still running, or stale JS | Wait a beat, then `Ctrl+Shift+R` |
| No email arrives | Correct — `MAIL_DRIVER=log` | Say so: it is recorded in `notifications` |

**The honest line if a demo step fails:** say what you expected, say what
happened, and move on. Then come back to it at the end if there is time. It
reads far better than silently clicking around.

---

## One-page running order

Tear-off version, for glancing at while you talk.

| # | Where | Do | Say |
|---|---|---|---|
| 1 | — | — | Two sides, one system |
| 2 | `/menu.php` | Scroll | 12 rows from MySQL, one query |
| 3 | `/account.php` | Register | bcrypt, salted, 60 chars, 200 ms |
| 4 | `/order.php` | 2 mandazi + 1 pilau, slot, Continue | Server recalculates the price |
| 5 | pay page | Simulate payment | Unique reference stops double charges |
| 6 | **Tab 2** | Reload | It is already there. Meta refresh, no JS |
| 7 | Tab 2 | Start cooking → Mark ready → Mark collected | One function defines the stages |
| 8 | Tab 1 → Tab 2 | Cancel, then delete | The red strip. Paid orders cannot be deleted |
| 9 | `/reserve.php` | Book, then double-book | The constraint, not the check |
| 10 | Home page | Triple-click the plate | Obscurity, not security |
| 11 | Editor | Show the tree | URL is the filename |
| 12 | — | — | Prepared statements in, escaping out |
