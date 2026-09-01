<?php

declare(strict_types=1);

/*
 | Küldési beállítások (spec 6.).
 |
 | A DRIVER-CSERE KIZÁRÓLAG ITT TÖRTÉNIK. Ha az éles indulás napján bármi más is kell a
 | `fake` → `postal` váltáshoz, mint ez a sor, akkor a kontraktus elromlott.
 */

return [
    'driver' => env('SENDING_DRIVER', 'fake'),

    'postal' => [
        'endpoint' => env('POSTAL_ENDPOINT', ''),
        'api_key' => env('POSTAL_API_KEY', ''),
    ],

    // 5. kapu — workspace-enkénti küldési sebesség.
    'rate_limit' => [
        'per_minute' => (int) env('SENDING_RATE_LIMIT_PER_MINUTE', 300),
    ],

    // 6. kapu — címellenőrzési kockázat. Per-domain kapcsolhatóvá az A5 teszi.
    'validation' => [
        'enabled' => (bool) env('SENDING_VALIDATION_GATE', true),
        'risk_threshold' => (float) env('SENDING_VALIDATION_THRESHOLD', 0.9),
        'disposable_domains' => ['mailinator.com', 'tempmail.com', 'guerrillamail.com'],
    ],

    // 4. kapu — amíg a ventPay nem él, innen jön a kvóta és az entitlement.
    'fake_entitlement' => [
        'remaining' => (int) env('FAKE_SEND_QUOTA', 10000),
        'features' => ['journeys', 'custom_domain', 'inbound_routes'],
    ],
];
