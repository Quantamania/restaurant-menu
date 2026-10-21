<?php
/* TEMPLATE for src/config.php — the one file to edit. */

return [

    /* -------------------------------------------------------- the database */
    'database' => [
        'host'   => 'localhost',
        'dbname' => 'restaurant_menu',
        'user'   => 'menu_user',
        'pass'   => '',
    ],

    /* --------------------------------------------- the kitchen screen door */
    'staff' => [
        // Does the kitchen screen ask for a password?
        'require_password' => true,   // STAFF_REQUIRE_PASSWORD

        // bcrypt hash only; never a readable password. Make one with:
        'password_hash' => '',    // STAFF_PASSWORD_HASH
    ],

    /* ------------------------------------------------------------- email */
    'mail' => [
        'driver'     => 'log',                  // MAIL_DRIVER: log | smtp | mail
        'from_email' => 'orders@example.com',   // MAIL_FROM
        'from_name'  => '',                     // MAIL_FROM_NAME
        'reply_to'   => '',                     // MAIL_REPLY_TO
        'smtp' => [
            'host'       => 'smtp.gmail.com',   // SMTP_HOST
            'port'       => 587,                // SMTP_PORT
            'encryption' => 'tls',              // SMTP_ENCRYPTION: tls | ssl | none
            'username'   => '',                 // SMTP_USERNAME
            // GMAIL: an App Password, NOT your Google password.
            'password'   => '',                 // SMTP_PASSWORD
            'timeout'    => 15,
        ],
    ],

    /* --------------------------------------------------------------- sms */
    'sms' => [
        'driver' => 'log',                      // SMS_DRIVER: log | africastalking
        'from'   => '',                         // SMS_FROM
        'africastalking' => [
            'username' => 'sandbox',            // AT_USERNAME
            'api_key'  => '',                   // AT_API_KEY
        ],
    ],

    /* ---------------------------------------------------------- payments */
    'payments' => [
        'driver' => 'manual',                   // PAYMENTS_DRIVER: manual | mpesa
        'mpesa' => [
            'env'             => 'sandbox',     // MPESA_ENV
            'shortcode'       => '',            // MPESA_SHORTCODE
            'passkey'         => '',            // MPESA_PASSKEY
            'consumer_key'    => '',            // MPESA_CONSUMER_KEY
            'consumer_secret' => '',            // MPESA_CONSUMER_SECRET
            'callback_url'    => '',            // MPESA_CALLBACK_URL
        ],
    ],

    // Used to build the tracking links inside emails.
    'base_url' => '',                           // BASE_URL
];
