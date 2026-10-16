<?php
/**
 * Site header: document head, opening <body>, and the navigation.
 *
 * Set these before requiring this file:
 *   $pageTitle  - string, shown in the browser tab (required)
 *   $pageStyle  - string, loads public/css/<name>.css for this page (optional)
 *   $pageScript - string, loads public/js/<name>.js for this page (optional)
 */
$pageStyle  = $pageStyle  ?? null;
$pageScript = $pageScript ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($pageTitle ?? 'Home') . ' | ' . $site['name']) ?></title>
  <link rel="stylesheet" href="css/style.css">
<?php if ($pageStyle): ?>
  <link rel="stylesheet" href="css/<?= e($pageStyle) ?>.css">
<?php endif; ?>
</head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main>
