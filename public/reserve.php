<?php
require __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'Reserve a table';
$errors    = [];
$old       = ['name' => '', 'email' => '', 'phone' => '',
              'reservation_date' => '', 'party_size' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    }

    foreach ($old as $key => $_) {
        $old[$key] = trim((string) ($_POST[$key] ?? ''));
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

    if (!$errors) {
        try {
            $stmt = getPDO()->prepare(
                'INSERT INTO reservations (name, email, phone, reservation_date, party_size, message)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $old['name'],
                $old['email'],
                $old['phone'] !== '' ? $old['phone'] : null,
                $old['reservation_date'],
                $size,
                $old['message'] !== '' ? $old['message'] : null,
            ]);
            $_SESSION['flash'] = 'Thank you! We have received your reservation request.';
            header('Location: reserve.php');
            exit;
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $errors['form'] = 'Sorry, we could not save your reservation. Please try again.';
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require __DIR__ . '/../src/includes/header.php';
?>
<!--
  Frontend markup to be implemented.

  Available to this template:
    $site   - site settings array
    $old    - submitted values to repopulate the form (name, email, phone,
              reservation_date, party_size, message)
    $errors - per-field error messages, keyed by field name; 'form' holds
              form-level errors
    $flash  - success message after a completed reservation, otherwise null

  The form must POST to reserve.php and include a hidden 'csrf' input set to
  csrf_token(), or the submission is rejected. Escape all output with e().
  A reference implementation of the previous design lives in
  frontend-reference/public/reserve.php.
-->
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
