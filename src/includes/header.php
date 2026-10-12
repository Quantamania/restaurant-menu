<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($pageTitle ?? 'Home') . ' | ' . $site['name']) ?></title>
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header class="site-header">
  <nav class="site-nav" aria-label="Main">
    <a class="brand" href="index.php"><?= e($site['name']) ?></a>
    <ul class="nav-links">
      <li><a href="index.php#menu">Menu</a></li>
      <li><a href="index.php#about">About</a></li>
      <li><a href="index.php#hours">Hours</a></li>
      <li><a href="reserve.php">Reserve</a></li>
    </ul>
  </nav>
</header>
<main>
