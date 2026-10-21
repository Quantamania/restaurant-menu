<?php
/* The first thing every page runs. */

// The session now decides who may read an order.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path'     => '/',
]);

session_start();

// Five files, in the order a request needs them. Each is one topic:
require __DIR__ . '/functions.php';   // the toolbox: database, escaping, CSRF
require __DIR__ . '/messaging.php';   // settings, email, SMS, what gets sent
require __DIR__ . '/payments.php';    // taking money
require __DIR__ . '/auth.php';        // customer accounts and the staff door
require __DIR__ . '/orders.php';      // the order lifecycle

$site = require __DIR__ . '/content.php';

// Run every date and time in the restaurant's own timezone.
date_default_timezone_set($site['timezone']);
