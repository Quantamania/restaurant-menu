<?php
// Kevin: replace this placeholder content with the real text.
return [
    // Where the restaurant is. Every date and time in the site is worked out
    // in this zone, not the server's. A server in another country must still
    // decide "has 1:30 pm gone?" using Nairobi's clock, not its own.
    'timezone' => 'Africa/Nairobi',

    'name'    => 'Maridadi Eat',

    // The dish on the big plate in the hero. Must match a name in the menu
    // exactly; if it does not, the hero falls back to the cheapest dish.
    'hero_dish' => 'Kenyan Coffee',
    'tagline' => 'Kenyan flavours, brewed and grilled fresh.',
    'about'   => 'We started as a small kitchen with one big idea: serve honest Kenyan food in a warm, relaxed space. Everything on our menu is cooked fresh each day.',
    'address' => 'Main Street, Your Town',

    // Where the pin sits on the map in the About section of the landing page.
    // Kevin: open openstreetmap.org, find the cafe, right-click it and choose
    // "Show address" — the numbers in the URL are the two you need here.
    // These placeholder ones point at the middle of Nairobi.
    'map' => [
        'lat'     => -1.286389,
        'lng'     => 36.817223,
        'zoom'    => 16,      // 13 = the whole city, 18 = the street
        'caption' => 'We are a short walk from the main stage, opposite the market.',
    ],
    'phone'   => '+254 700 000 000',
    'email'   => 'hello@example.com',
    // The seatings people can book. Keys go into the database, values are
    // what the visitor reads.
    'slots'   => [
        '12:00:00' => 'Lunch — 12:00 pm',
        '13:30:00' => 'Late lunch — 1:30 pm',
        '18:00:00' => 'Early dinner — 6:00 pm',
        '19:30:00' => 'Dinner — 7:30 pm',
        '21:00:00' => 'Late dinner — 9:00 pm',
    ],
    // How the kitchen takes orders (order.php).
    'ordering' => [
        'lead_minutes'     => 45,    // shortest notice the kitchen will accept
        'delivery_fee'     => 200,   // flat fee, in KSh
        'delivery_minimum' => 800,   // smallest order we will drive out
        'max_per_dish'     => 20,    // sanity cap on one dish
    ],

    // How money is taken (pay.php).
    //
    // Kevin: put the restaurant's REAL till number here. It is left empty on
    // purpose — a made-up paybill is somebody else's real account, and an
    // order paid into it is money gone. While it is empty the payment screen
    // simply says to pay on collection, which is true and harmless.
    'payment' => [
        'paybill'  => '',           // e.g. 522522 — a Paybill number
        'till'     => '',           // or a Buy Goods till number
        // What the customer types in the "account" box. The order reference
        // is the right answer: it is what ties their money to their food.
        'account'  => 'your order reference',
    ],

    // When a food order can be collected or delivered (order.php).
    'order_times' => [
        '11:30:00' => '11:30 am',
        '12:30:00' => '12:30 pm',
        '13:30:00' => '1:30 pm',
        '17:30:00' => '5:30 pm',
        '18:30:00' => '6:30 pm',
        '19:30:00' => '7:30 pm',
        '20:30:00' => '8:30 pm',
    ],
    'hours'   => [
        'Monday to Friday' => '7:00 am - 9:00 pm',
        'Saturday'         => '8:00 am - 10:00 pm',
        'Sunday'           => '9:00 am - 6:00 pm',
    ],
];
