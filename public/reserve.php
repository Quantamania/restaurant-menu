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
<section class="reserve">
  <h1 class="section-title">Reserve a table</h1>

  <?php if ($flash): ?>
    <p class="notice notice-success" role="status"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if (isset($errors['form'])): ?>
    <p class="notice notice-error" role="alert"><?= e($errors['form']) ?></p>
  <?php endif; ?>

  <form method="post" action="reserve.php" class="reserve-form" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" type="text" maxlength="100" required value="<?= e($old['name']) ?>">
      <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" maxlength="150" required value="<?= e($old['email']) ?>">
      <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="phone">Phone (optional)</label>
      <input id="phone" name="phone" type="tel" maxlength="20" value="<?= e($old['phone']) ?>">
      <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="reservation_date">Date</label>
      <input id="reservation_date" name="reservation_date" type="date" min="<?= date('Y-m-d') ?>" required value="<?= e($old['reservation_date']) ?>">
      <?php if (isset($errors['reservation_date'])): ?><span class="field-error"><?= e($errors['reservation_date']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="party_size">Number of guests</label>
      <input id="party_size" name="party_size" type="number" min="1" max="20" required value="<?= e($old['party_size']) ?>">
      <?php if (isset($errors['party_size'])): ?><span class="field-error"><?= e($errors['party_size']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="message">Message (optional)</label>
      <textarea id="message" name="message" rows="4" maxlength="500"><?= e($old['message']) ?></textarea>
      <?php if (isset($errors['message'])): ?><span class="field-error"><?= e($errors['message']) ?></span><?php endif; ?>
    </div>

    <button type="submit" class="button">Send reservation</button>
  </form>
</section>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
