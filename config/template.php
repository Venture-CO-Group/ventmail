<?php

declare(strict_types=1);

return [
    /*
     | v1.1 · a sablon-szerkesztő képei (D10).
     |
     | `driver`: `local` (a `public` diszk) | `null` (kikapcsolt feltöltés).
     | TODO ventVault: `ventvault` driver, amint a ventVault public-asset API él (HUMAN-QUEUE).
     */
    'assets' => [
        'driver' => env('TEMPLATE_ASSET_DRIVER', 'local'),
        'disk' => env('TEMPLATE_ASSET_DISK', 'public'),
        'public_url' => env('TEMPLATE_ASSET_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage'),
        // A KORLÁTOK: egy 20 MB-os kép a levél letöltését teszi lassúvá, egy 10 000 px széles
        // pedig a mobil klienst. 5 MB és 4000 px bőven elég egy levélképhez.
        'max_bytes' => (int) env('TEMPLATE_ASSET_MAX_BYTES', 5 * 1024 * 1024),
        'max_dimension' => (int) env('TEMPLATE_ASSET_MAX_DIMENSION', 4000),
    ],

    /*
     | Teszt-küldés a szerkesztőből: egyszerre legfeljebb ennyi címzett. A címzett csak a
     | workspace tagja vagy a platform seed-listájának címe lehet.
     */
    'test_send' => [
        'max_recipients' => 5,
    ],
];
