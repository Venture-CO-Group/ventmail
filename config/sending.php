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

    // 0. kapu — bemenet-épség. A spec 8.1 hívásonként max 1000 címzettet enged.
    'max_recipients' => (int) env('SENDING_MAX_RECIPIENTS', 1000),

    // A beadott nyers MIME felső korlátja (a Mailgun 25 MB-ot enged csatolmányokkal).
    'max_mime_bytes' => (int) env('SENDING_MAX_MIME_BYTES', 26_214_400),

    // Meddig őrizzük a beadott nyers levelet (golden rule 10). A plan-függő tier a C1
    // (ventPay `log_retention_tier`) dolga; ez a biztonságos alapérték.
    'raw_mime_retention_days' => (int) env('SENDING_RAW_MIME_RETENTION_DAYS', 30),

    // A `sending` állapot lízingje: ennyi idő után egy félbemaradt küldés újraigényelhető.
    'lease_seconds' => (int) env('SENDING_LEASE_SECONDS', 300),

    // A fake külső gazdák (consent, kockázat, kvóta) csak nem-éles környezetben köthetők be:
    // élesben némán kinyitnák a 3., 4. és 6. kaput.
    'allow_fake_dependencies' => (bool) env('SENDING_ALLOW_FAKE_DEPENDENCIES', true),

    // A gate-döntés szavatossága: ennél régebbi döntéssel nem indul küldés (ütemezett
    // kampánynál a közben érkezett leiratkozásnak számítania kell).
    'decision_max_age_seconds' => (int) env('SENDING_DECISION_MAX_AGE', 900),

    'postal' => [
        'endpoint' => env('POSTAL_ENDPOINT', ''),
        'api_key' => env('POSTAL_API_KEY', ''),
    ],

    // A kézbesítési kísérletek (fogadó válasza, címzett) megőrzése — PII, tehát lejár.
    // A nyomkövetési linkek gazdája. Külön kulcs, mert ez a cím a LEVÉLBEN megy ki: a
    // levélkliens hívja, nem a bejelentkezett böngésző, tehát nem feltétlenül ugyanaz a
    // hoszt, mint az alkalmazásé (külön aldomain, saját tanúsítvány, saját CDN).
    'tracking_base_url' => env('SENDING_TRACKING_BASE_URL'),

    'delivery_attempt_retention_days' => (int) env('SENDING_ATTEMPT_RETENTION_DAYS', 90),

    // A küldés SORON megy-e (A2). Fejlesztésben és tesztben szinkron: a determinisztikus
    // teszt fontosabb, mint a sor gyakorlása. Élesben a sor a prioritás (golden rule 4)
    // egyetlen érvényesítési helye — a Horizon `queue` sorrendje adja.
    'dispatch_via_queue' => (bool) env('SENDING_DISPATCH_VIA_QUEUE', false),

    // Per-FOGADÓ-domain sebességkorlát (A2, 19. kritérium). A nagy fogadók saját
    // küszöbökkel dolgoznak: túl gyors forgalomra előbb 4xx-eznek, aztán blokkolnak.
    'domain_throttle' => [
        'default' => (int) env('SENDING_DOMAIN_THROTTLE', 60),
        'limits' => [
            'gmail.com' => (int) env('SENDING_THROTTLE_GMAIL', 100),
            'googlemail.com' => (int) env('SENDING_THROTTLE_GMAIL', 100),
            'outlook.com' => (int) env('SENDING_THROTTLE_OUTLOOK', 60),
            'hotmail.com' => (int) env('SENDING_THROTTLE_OUTLOOK', 60),
            'yahoo.com' => (int) env('SENDING_THROTTLE_YAHOO', 60),
        ],
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
        'features' => ['journeys', 'custom_domain', 'inbound_routes', 'dedicated_ip'],
    ],
];
