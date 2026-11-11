<?php
/* RESERVATION PAGE */

require __DIR__ . '/includes/setup.php';

$pageTitle = 'Reserve a table';
$crumbs    = [['Home', 'index.php'], ['Reserve a table', null]];
$errors    = [];
$action    = $_POST['action'] ?? null;

$old = ['name' => '', 'email' => '', 'phone' => '', 'reservation_date' => date('Y-m-d'),
        'reservation_time' => array_key_first($site['slots']), 'table_id' => '',
        'party_size' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $_) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            $old[$key] = trim((string) $_POST[$key]);
        } elseif ($key !== 'reservation_date' && $key !== 'reservation_time') {
            $old[$key] = '';
        }
    }
}

/* ---------------------------------- managing your own booking */

/* Both actions are guarded by may_view_reservation(), so a booking id typed
 * into the address bar is not enough — it has to be yours. */
if (in_array($action, ['cancel-booking', 'delete-booking'], true)) {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'That form had expired. Nothing was changed — please try again.';
    } else {
        $bookingId = (int) ($_POST['reservation_id'] ?? 0);
        $booking   = $bookingId > 0 ? get_reservation($bookingId) : null;

        if (!$booking || !may_view_reservation($booking)) {
            $errors['form'] = 'We cannot find that booking.';
        } else {
            $result = $action === 'cancel-booking'
                ? cancel_reservation($bookingId, 'customer')
                : delete_reservation($bookingId, 'customer');

            if ($result['ok']) {
                if ($action === 'delete-booking') {
                    // Drop it from the guest list too, or it lingers as a dead id.
                    $_SESSION['my_bookings'] = array_values(array_diff(
                        array_map('intval', $_SESSION['my_bookings'] ?? []), [$bookingId]
                    ));
                }

                $_SESSION['flash'] = $action === 'cancel-booking'
                    ? 'Your booking is cancelled, and the table is free again.'
                    : 'That booking has been deleted.';

                header('Location: reserve.php');
                exit;
            }

            $errors['form'] = $result['error'];
        }
    }
}


// A slot we do not recognise falls back to the first one.
if (!isset($site['slots'][$old['reservation_time']])) {
    $old['reservation_time'] = array_key_first($site['slots']);
}

// Which tables are free for the chosen date and time.
$tables = [];

try {
    $tables = getTablesWithAvailability($old['reservation_date'], $old['reservation_time']);
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $errors['form'] = 'We cannot reach the booking system right now. Please try again later.';
}

/* ------------------------------------------------------------------ booking */

if ($action === 'book' && !isset($errors['form'])) {

    // Was this really our own form? (see csrf_token() in includes/functions.php)
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    }

    if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 100) {
        $errors['name'] = 'Enter your name (2 to 100 characters).';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 150) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($old['phone'] !== '' && !preg_match('/^\+?[0-9 ]{7,20}$/', $old['phone'])) {
        $errors['phone'] = 'Use digits only, for example +254 700 000 000.';
    }

    $date = DateTime::createFromFormat('Y-m-d', $old['reservation_date']);
    if (!$date || $date->format('Y-m-d') !== $old['reservation_date']) {
        $errors['reservation_date'] = 'Choose a valid date.';
    } elseif ($old['reservation_date'] < date('Y-m-d')) {
        $errors['reservation_date'] = 'The date cannot be in the past.';
    }

    $size = filter_var($old['party_size'], FILTER_VALIDATE_INT,
                       ['options' => ['min_range' => 1, 'max_range' => 20]]);
    if ($size === false) {
        $errors['party_size'] = 'Party size must be between 1 and 20.';
    }

    if (mb_strlen($old['message']) > 500) {
        $errors['message'] = 'Keep your message under 500 characters.';
    }

    // --- the table: it must exist, be big enough, and still be free ---
    $table = $old['table_id'] !== '' ? getTable((int) $old['table_id']) : null;

    if (!$table) {
        $errors['table_id'] = 'Choose a table.';
    } else {
        if ($size !== false && $table['seats'] < $size) {
            $errors['table_id'] = "Table {$table['label']} seats {$table['seats']}. Pick a bigger one.";
        }

        // Re-check here, not just in the picker.
        foreach ($tables as $t) {
            if ((int) $t['id'] === (int) $table['id'] && $t['is_taken']) {
                $errors['table_id'] = "Table {$table['label']} has just been booked. Please choose another.";
            }
        }
    }

    // --- nothing wrong? save the booking and its pre-order together ---

    if (!$errors) {
        $pdo = getPDO();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO reservations
                   (user_id, name, email, phone, reservation_date, reservation_time,
                    table_id, party_size, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $me = current_user();

            $stmt->execute([
                $me['id'] ?? null,
                $old['name'],
                $old['email'],
                $old['phone'] !== '' ? $old['phone'] : null,
                $old['reservation_date'],
                $old['reservation_time'],
                (int) $table['id'],
                $size,
                $old['message'] !== '' ? $old['message'] : null,
            ]);

            /* Remembered in the session as well as against the account, so a
             * guest who booked without signing in can still see and cancel it. */
            $_SESSION['my_bookings'][] = (int) getPDO()->lastInsertId();

            $when = date('j M Y', strtotime($old['reservation_date']));
            $slot = $site['slots'][$old['reservation_time']];

            $_SESSION['flash'] = "Thank you! Table {$table['label']} is held for you on {$when}, {$slot}.";
            header('Location: reserve.php');
            exit;

        } catch (Throwable $ex) {
            // 23000 is the database refusing a duplicate.
            if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                $errors['table_id'] = "Table {$table['label']} has just been booked. Please choose another.";
                $tables = getTablesWithAvailability($old['reservation_date'], $old['reservation_time']);
            } else {
                error_log($ex->getMessage());
                $errors['form'] = 'Sorry, we could not save your reservation. Please try again.';
            }
        }
    }
}

// The success message set just before the redirect above, shown once.
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Bookings this visitor may manage: their account's, plus this session's.
$myBookings = [];
try {
    $myBookings = reservations_for_viewer();
} catch (Throwable $ex) {
    error_log('reservations_for_viewer: ' . $ex->getMessage());
}

// Small shortcut: adds the red-outline class to a field that has an error.
$bad = static fn(string $field): string => !empty($errors[$field]) ? ' field--bad' : '';

// Party size, used to grey out tables that are too small.
$party = (int) $old['party_size'];

// Group the tables by area.
$byArea = [];
foreach ($tables as $t) {
    $byArea[$t['area']][] = $t;
}

// What the stub on the left shows: the table they have chosen, if any.
$chosen = null;
foreach ($tables as $t) {
    if ((string) $old['table_id'] === (string) $t['id']) {
        $chosen = $t;
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="reserve">
  <div class="wrap">

    <div class="section-head">
      <span class="section-eyebrow">Book a table</span>
      <h1 class="section-title">Reserve</h1>
    </div>

    <?php if ($flash): ?>
      <p class="msg msg--ok">
        <span class="msg__icon" aria-hidden="true">&check;</span>
        <span><?= e($flash) ?></span>
      </p>
    <?php endif; ?>

    <?php if (!empty($errors['form'])): ?>
      <p class="msg msg--bad">
        <span class="msg__icon" aria-hidden="true">!</span>
        <span><?= e($errors['form']) ?></span>
      </p>
    <?php endif; ?>

    <?php /* Your own bookings, with the two things you might want to do to
           * one: free the table but keep the record, or remove it entirely. */ ?>
    <?php if ($myBookings): ?>
      <?php
        // Only the ones still ahead count as "you have a table reserved".
        $upcoming = array_filter($myBookings, fn ($b) =>
            $b['status'] === 'booked'
            && strtotime($b['reservation_date'] . ' ' . $b['reservation_time']) >= time());
      ?>
      <?php /* The same card the kitchen uses for a booking: a dark panel, the
             * time in Anton, and a Table / Party split with a rule between. */ ?>
      <?php require __DIR__ . '/includes/sections/reserve/my-bookings.php'; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/sections/reserve/booking-form.php'; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
