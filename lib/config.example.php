<?php
declare(strict_types=1);

return [
    // --- database (required) ---
    'db' => [
        'host'    => 'localhost',   // Hostinger preset; rarely changes
        'port'    => 3306,
        'name'    => 'u123456789_pantrypivot',
        'user'    => 'u123456789_pantrypivot',
        'pass'    => 'CHANGE_ME',
    ],

    // --- app (optional, one line) ---
    // 'app_url' => 'https://pantrypivot.earth-turtle.com', // needed only if absolute URLs appear

    // --- debug (always present) ---
    'debug' => false, // true = display errors, no .htaccess-internal blocking in logs
];