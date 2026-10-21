<?php
// Kevin: replace this placeholder content with the real text.
return [
    // Where the restaurant is.
    'timezone' => 'Africa/Nairobi',

    'name'    => 'Maridadi Eat',

    // The dish on the big plate in the hero.
    'hero_dish' => 'Kenyan Coffee',
    'tagline' => 'Kenyan flavours, brewed and grilled fresh.',
    'about'   => 'We started as a small kitchen with one big idea: serve honest Kenyan food in a warm, relaxed space. Everything on our menu is cooked fresh each day.',
    'address' => 'Kenyatta Avenue, Nakuru CBD',

    // Where the pin sits on the map in the About section of the landing page.
    'map' => [
        'lat'     => -0.283333,
        'lng'     => 36.066667,
        'zoom'    => 16,      // 13 = the whole town, 18 = the street
        'caption' => 'In the middle of Nakuru town, on Kenyatta Avenue.',
    ],
    'phone'   => '+254 700 000 000',
    'email'   => 'maridadieat@gmail.com',
    // The seatings people can book.
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
    'payment' => [
        'paybill'  => '',           // e.g. 522522 — a Paybill number
        'till'     => '',           // or a Buy Goods till number
        // What the customer types in the "account" box.
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
