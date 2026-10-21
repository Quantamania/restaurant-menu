<?php
/* ===========================================================================
 * TEMPLATE for src/secrets.php — the keys and passwords the site needs to
 * send email, send SMS and take payments.
 *
 * HOW TO USE IT
 *
 *   cp src/secrets.example.php src/secrets.php
 *   then edit src/secrets.php and fill in the blanks.
 *
 * src/secrets.php is gitignored. This file, with the blanks left empty, is
 * the one that gets committed — so a new developer can see WHICH values are
 * needed without any of them being published.
 *
 * Every value can also come from an environment variable, and the
 * environment WINS over this file. That is the better way to do it on a
 * real server: a password in an environment variable is not sitting in a
 * file that a misconfigured web server could serve as plain text. The
 * variable name for each setting is in the comment beside it.
 *
 * Nothing here is required to develop. With no secrets.php at all, every
 * driver falls back to 'log': messages are written to the notifications
 * table and to storage/mail/, and nothing leaves the machine. The whole
 * ordering, payment and tracking flow still works end to end.
 * =========================================================================== */

return [

    /* -------------------------------------------------- the kitchen screen */
    'staff' => [
        // Does public/kitchen.php ask for a password?
        //
        // false — it opens straight onto the board. Convenient while you are
        //         building, and fine on a laptop nobody else can reach.
        // true  — it asks for the password below. What you want on anything
        //         with a public address.
        //
        // This defaults to TRUE on purpose. The kitchen screen lists every
        // open order's name, phone number and home address on one page, and
        // its buttons cancel orders and mark them paid — so a copy of this
        // project that someone deploys without reading the setup notes must
        // not be handing that out. Turn it off deliberately, in your own
        // gitignored secrets.php, not by default for everybody.
        //
        // With it off the screen shows a standing warning, so an open board
        // is never a silent surprise.
        'require_password' => true,    // STAFF_REQUIRE_PASSWORD=false to open it

        // The password for public/kitchen.php, stored as a bcrypt HASH —
        // never as the password itself. Generate one with:
        //
        //     php tools/staff-password.php
        //
        // It asks for the password without showing it on screen, and prints
        // the line to paste here. Nothing readable is ever written to a
        // file or left in your shell history.
        //
        // While this is empty the kitchen screen REFUSES TO OPEN. It does
        // not fall back to "no password needed" — a half-installed site
        // would then be publishing its whole customer list.
        'password_hash' => '',         // STAFF_PASSWORD_HASH
    ],

    /* ------------------------------------------------------------------ mail */
    'mail' => [
        // log  — write it to the outbox and storage/mail/, send nothing.
        //        The default, and what you want while building.
        // smtp — really send it, by talking to an SMTP server.
        // mail — hand it to PHP's mail(), which needs a working local MTA.
        //        This machine has no sendmail binary, so it will fail here.
        'driver' => 'log',                 // MAIL_DRIVER

        // Who the email appears to come from. With Gmail, from_email must be
        // the same account as the username below, or Gmail rewrites it.
        'from_email' => 'orders@example.com',   // MAIL_FROM
        'from_name'  => '',                     // MAIL_FROM_NAME, defaults to the site name

        // Where replies should go, if that is not the From address.
        'reply_to' => '',                  // MAIL_REPLY_TO

        'smtp' => [
            'host' => 'smtp.gmail.com',    // SMTP_HOST
            'port' => 587,                 // SMTP_PORT
            // tls  — connect in the clear on 587, then upgrade (STARTTLS)
            // ssl  — connect already encrypted on 465
            // none — no encryption. Only ever for a local test server.
            'encryption' => 'tls',         // SMTP_ENCRYPTION
            'username'   => '',            // SMTP_USERNAME
            // GMAIL: this is an App Password (16 letters, from
            // myaccount.google.com → Security → App passwords), NOT your
            // normal Google password. Google refuses plain account
            // passwords over SMTP, and an App Password can be revoked on
            // its own without changing the password you log in with.
            'password'   => '',            // SMTP_PASSWORD
            'timeout'    => 15,            // seconds
        ],
    ],

    /* ------------------------------------------------------------------- sms */
    'sms' => [
        // log            — write it to the outbox, send nothing (default)
        // africastalking — really send it
        'driver' => 'log',                 // SMS_DRIVER

        // The name the message appears to come from. A real alphanumeric
        // sender ID has to be registered with the provider first; until it
        // is, leave it empty and the provider uses a shared short code.
        'from' => '',                      // SMS_FROM

        // africastalking.com — the usual choice for Kenyan numbers. The
        // sandbox username is literally "sandbox" and costs nothing.
        'africastalking' => [
            'username' => 'sandbox',       // AT_USERNAME
            'api_key'  => '',              // AT_API_KEY
        ],
    ],

    /* -------------------------------------------------------------- payments */
    'payments' => [
        // manual — no gateway. An M-Pesa payment is recorded as pending and
        //          somebody at the till confirms it against the message on
        //          their own phone. Works today, needs no credentials.
        // mpesa  — Safaricom Daraja STK push. Needs the four values below
        //          and a publicly reachable callback URL, so it cannot be
        //          tested from a laptop without a tunnel.
        'driver' => 'manual',              // PAYMENTS_DRIVER

        'mpesa' => [
            'env'            => 'sandbox', // MPESA_ENV: sandbox | live
            'shortcode'      => '',        // MPESA_SHORTCODE
            'passkey'        => '',        // MPESA_PASSKEY
            'consumer_key'   => '',        // MPESA_CONSUMER_KEY
            'consumer_secret'=> '',        // MPESA_CONSUMER_SECRET
            // Safaricom POSTs the result here. Must be https and reachable
            // from the internet — not localhost.
            'callback_url'   => '',        // MPESA_CALLBACK_URL
        ],
    ],

    // The site's own address, used to build the "track my order" link that
    // goes into emails. Without it the link is guessed from the request,
    // which is wrong in an email sent from a cron job.
    'base_url' => '',                      // BASE_URL, e.g. https://maridadi.co.ke
];
