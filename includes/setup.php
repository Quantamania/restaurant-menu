<?php
/* The first thing every page runs. */

/* Errors are written to the log, never printed.
 *
 * A notice printed before the HTML sends the headers early, and after that
 * session_start() cannot run — which breaks every form on the site while the
 * pages still look fine. Visitors see one plain sentence; the detail goes to
 * the host's function log. Locally, keep them on screen. */
$isLocal = in_array(explode(':', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')))[0],
                    ['localhost', '127.0.0.1', '::1'], true);

ini_set('display_errors', $isLocal ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// The session now decides who may read an order.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path'     => '/',
]);

// Sessions may need to live in the database — see includes/session.php. The
// handler has to be registered before the session starts.
require __DIR__ . '/functions.php';
require __DIR__ . '/session.php';

if (sessions_in_database()) {
    use_database_sessions();
}

session_start();

// Four topic files, in the order a request needs them:
require __DIR__ . '/messaging.php';   // settings, email, SMS, what gets sent
require __DIR__ . '/payments.php';    // taking money
require __DIR__ . '/auth.php';        // customer accounts and the staff door
require __DIR__ . '/orders.php';      // the order lifecycle

$site = require __DIR__ . '/content.php';

// Run every date and time in the restaurant's own timezone.
date_default_timezone_set($site['timezone']);
