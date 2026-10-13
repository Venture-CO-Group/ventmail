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
        // ALAPBÓL KIKAPCSOLVA (S7): élesben a `public` diszk nincs kiszolgálva (HUMAN-QUEUE:
        // ventVault / storage-kiszolgálás). Fejlesztésben, tesztben és e2e-ben `local`.
        'driver' => env('TEMPLATE_ASSET_DRIVER', 'null'),
        'disk' => env('TEMPLATE_ASSET_DISK', 'public'),
        'public_url' => env('TEMPLATE_ASSET_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage'),
        // A KORLÁTOK: egy 20 MB-os kép a levél letöltését teszi lassúvá, egy 10 000 px széles
        // pedig a mobil klienst. 5 MB és 4000 px bőven elég egy levélképhez.
        'max_bytes' => (int) env('TEMPLATE_ASSET_MAX_BYTES', 5 * 1024 * 1024),
        'max_dimension' => (int) env('TEMPLATE_ASSET_MAX_DIMENSION', 4000),
        // A workspace TELJES képtárhelye (SS5).
        // A törölt workspace képei ennyi nap után törlődnek (S3: a soft delete visszavonható).
        // A kvóta-zárra (S1) legfeljebb ennyit vár egy feltöltés.
        'lock_wait_seconds' => (int) env('TEMPLATE_ASSET_LOCK_WAIT', 10),
        'purge_grace_days' => (int) env('TEMPLATE_ASSET_PURGE_GRACE_DAYS', 30),
        'workspace_quota_bytes' => (int) env('TEMPLATE_ASSET_WORKSPACE_QUOTA', 500 * 1024 * 1024),
    ],

    /*
     | Teszt-küldés a szerkesztőből: egyszerre legfeljebb ennyi címzett. A címzett csak a
     | workspace tagja vagy a platform seed-listájának címe lehet.
     */
    /*
     | A „megnyitás böngészőben" token élettartama napokban (SS2).
     */
    'web_version_ttl_days' => (int) env('TEMPLATE_WEB_VERSION_TTL_DAYS', 30),

    'test_send' => [
        'max_recipients' => 5,
    ],
];
