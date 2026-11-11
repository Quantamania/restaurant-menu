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
/* The timezone is set FIRST, before anything can open a connection.
 *
 * getPDO() tells the database which offset to work in, using PHP's timezone
 * at the moment it connects. With database-backed sessions the first connect
 * happens during setup, so if the timezone were set afterwards the connection
 * would be left on UTC while PHP ran on Nairobi time — three hours of skew
 * between NOW() and every date PHP computes. */
$site = require __DIR__ . '/content.php';
date_default_timezone_set($site['timezone']);

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
