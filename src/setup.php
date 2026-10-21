<?php
/* ===========================================================================
 * The first thing every page runs.
 *
 * public/index.php and public/reserve.php both start with:
 *
 *     require __DIR__ . '/../src/setup.php';
 *
 * That one line starts the visitor's session, loads the toolbox in
 * functions.php, reads the restaurant's text from content.php into a variable
 * called $site, and sets the clock to the restaurant's own timezone.
 * =========================================================================== */

// The session now decides who may read an order, so its cookie is worth
// locking down before it is handed out.
//
//   httponly  JavaScript cannot read it, so a cross-site scripting bug
//             cannot be turned into "here is somebody's session"
//   samesite  the browser does not attach it to requests started by
//             another site, which is a second line behind the CSRF tokens
//   secure    only sent over https — set only WHEN we are on https, or a
//             local http dev server would hand out a cookie the browser
//             then refuses to send back, and nothing would work
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path'     => '/',
]);

session_start();

require __DIR__ . '/functions.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/sms.php';
require __DIR__ . '/notify.php';
require __DIR__ . '/payments.php';
require __DIR__ . '/accounts.php';
require __DIR__ . '/staff.php';

$site = require __DIR__ . '/content.php';

// Run every date and time in the restaurant's own timezone. Without this PHP
// uses the server's, which is often UTC — and then "is that slot in the past?"
// is answered against the wrong clock.
date_default_timezone_set($site['timezone']);
