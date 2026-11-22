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

    /*
     | ÉLES KÜLDÉSI MÓD (deploy-ovh, kemény szabály 3). Mindhárom kapcsolót KIZÁRÓLAG a
     | tulajdonos fordítja át — az alapérték az, amivel egy friss éles gép biztonságosan indul.
     |
     | - `live = false`: SEED-MÓD. Csak az allowlistes címzett kaphat levelet, akkor is, ha az
     |   allowlist kötelezőségét valaki kikapcsolta (a kettő együtt sem nyit ki semmit).
     | - `allowlist_enforced`: élesben is csak az allowlist — a warm-up első lépcsőjéhez.
     | - `marketing_enabled = false`: minden consent-köteles csatorna (marketing, campaign,
     |   journey) tiltott, allowlistes címre is.
     |
     | Az allowlist bejegyzése teljes cím (`a@b.hu`) vagy domain (`@b.hu`, aldomain nélkül).
     */
    'live' => (bool) env('SENDING_LIVE', false),
    'marketing_enabled' => (bool) env('SENDING_MARKETING_ENABLED', false),
    'allowlist_enforced' => (bool) env('SEND_ALLOWLIST_ENFORCED', true),
    'allowlist' => array_values(array_filter(array_map(
        static fn (string $entry) => strtolower(trim($entry)),
        explode(',', (string) env('SEND_ALLOWLIST', ''))
    ))),

    // 0. kapu — bemenet-épség. A spec 8.1 hívásonként max 1000 címzettet enged.
    'max_recipients' => (int) env('SENDING_MAX_RECIPIENTS', 1000),

    // A beadott nyers MIME felső korlátja (a Mailgun 25 MB-ot enged csatolmányokkal).
    'max_mime_bytes' => (int) env('SENDING_MAX_MIME_BYTES', 26_214_400),

    /*
     | KIMENŐ CSATOLMÁNY a `POST /v1/messages`-en (JSON, base64; H123).
     |
     | - Allowlist: képek (JPEG, PNG, GIF, WebP); a PDF a katalógusban van, de alapból KI
 |   (`SENDING_ATTACHMENT_TYPES`). A deklarált típusnak a tartalom
     |   mágikus bájtjaival egyeznie kell (`ATTACHMENT_TYPE_MISMATCH`). SVG/HTML nincs:
     |   szkriptet hordozhat, és a levélkliensek egy része megnyitja.
     | - Méret: darabonként és üzenetenként; a `max_batch_bytes` a csatolmány-bájt × címzett
     |   szorzat felső korlátja — a MIME címzettenként épül, és a worker memóriája véges.
     |   A kódolt kérés a `APP_MAX_BODY_MB` (16 MB) alá kell férjen: 10 MB bináris ≈ 13,4 MB
     |   base64.
     | - Megőrzés: a bájtok TITKOSÍTVA (APP_KEY) a `message_attachments` táblában, és csak a
     |   kiküldésig / az újrapróbálás végéig élnek. A lezárt üzenet (delivered, sent, bounced,
     |   failed, rejected) bájtjai azonnal törlődnek; a `retention_hours` a felső határ
     |   (ütemezett levélnél a kiküldés időpontjától számítva). A `messages` soron csak a
     |   típus és a méret marad (trace), fájlnév nem.
     */
    'attachments' => [
        // Az ENGEDETT típusok — a `AttachmentParser::ALLOWED` katalógusát csak SZŰKÍTHETI
        // (ismeretlen típushoz nincs tartalom-szippantás). A PDF ALAPBÓL KI: amíg a kimenő
        // úton nincs fail-closed víruskereső (`VirusScanner`), egy kompromittált API-kulccsal
        // a mi IP-inkről, a mi DKIM-ünkkel menne ki kártevő (S3, HUMAN-QUEUE).
        'allowed_types' => array_values(array_filter(array_map(
            static fn (string $type) => strtolower(trim($type)),
            explode(',', (string) env('SENDING_ATTACHMENT_TYPES', 'image/jpeg,image/png,image/gif,image/webp'))
        ))),
        'max_count' => (int) env('SENDING_ATTACHMENT_MAX_COUNT', 10),
        'max_file_bytes' => (int) env('SENDING_ATTACHMENT_MAX_FILE_BYTES', 5 * 1024 * 1024),
        'max_total_bytes' => (int) env('SENDING_ATTACHMENT_MAX_TOTAL_BYTES', 10 * 1024 * 1024),
        'max_batch_bytes' => (int) env('SENDING_ATTACHMENT_MAX_BATCH_BYTES', 50 * 1024 * 1024),
        'retention_hours' => (int) env('SENDING_ATTACHMENT_RETENTION_HOURS', 48),
    ],

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
        // Egy-végpontos (régi) beállítás: KIZÁRÓLAG tranzakciós forgalomra esik vissza rá a
        // driver, ha a `pools.transactional` üres. Marketing-forgalmat SOSEM visz.
        'endpoint' => env('POSTAL_ENDPOINT', ''),
        'api_key' => env('POSTAL_API_KEY', ''),

        /*
         | POOLONKÉNT KÜLÖN POSTAL-PÉLDÁNY (deploy-ovh, kemény szabály 5). Mindegyik a saját
         | IP-jéről, a saját HELO-jával és a saját Return-Path domainjével küld; a csatorna
         | pool-osztálya (`Channel::poolClass()`) dönti el, melyiket hívjuk. A két példány
         | végpontja és Return-Path domainje nem egyezhet — a driver ellenőrzi.
         |
         | A végpont `https://`, vagy `http://` EGYCÍMKÉS hostnévvel (docker belső hálózat).
         */
        'pools' => [
            'transactional' => [
                'endpoint' => env('POSTAL_TX_ENDPOINT', ''),
                'api_key' => env('POSTAL_TX_API_KEY', ''),
                'return_path_prefix' => env('POSTAL_TX_RETURN_PATH_PREFIX', 'psrp-tx'),
                'return_path_domain' => env('POSTAL_TX_RETURN_PATH_DOMAIN', 'rp-tx.ventmail.app'),
                // A példány webhook-aláíró PUBLIKUS kulcsa (PEM). Nem titok, de példányonként más.
                'webhook_public_key' => str_replace('\\n', "\n", (string) env('POSTAL_TX_WEBHOOK_PUBLIC_KEY', '')),
            ],
            'marketing' => [
                'endpoint' => env('POSTAL_MK_ENDPOINT', ''),
                'api_key' => env('POSTAL_MK_API_KEY', ''),
                'return_path_prefix' => env('POSTAL_MK_RETURN_PATH_PREFIX', 'psrp-mk'),
                'return_path_domain' => env('POSTAL_MK_RETURN_PATH_DOMAIN', 'rp-mk.ventmail.app'),
                'webhook_public_key' => str_replace('\\n', "\n", (string) env('POSTAL_MK_WEBHOOK_PUBLIC_KEY', '')),
            ],
        ],
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
        // MIND AZ ÖT funkció (C1 · 76. kritérium). A `validation_bulk` korábban KIMARADT
        // ebből a listából, tehát a kötegelt címellenőrzés jogosultsága SOSEM teljesült
        // volna — egy „engedélyezett" funkció, amit senki nem tud bekapcsolni.
        'features' => ['journeys', 'custom_domain', 'inbound_routes', 'dedicated_ip', 'validation_bulk'],

        // WORKSPACE-ENKÉNTI felülírás (`id => [funkciók]`). A `EntitlementChecker`
        // parent-feloldását ezzel lehet mérni: enélkül a fake workspace-vak volt, és a
        // „sub-account a parent csomagját használja" szabályt semmi nem bizonyította.
        'per_workspace' => [],

        /*
         | RENDEZETLEN periódusok (`YYYY-MM` vagy `workspace:YYYY-MM`).
         |
         | ÜRES alapérték: amíg a ventPay nem él, MINDEN periódus rendezett. A felfüggesztés
         | a legdurvább eszköz (a tranzakciós levelet is megállítja), és a review megmérte,
         | hogy a fordított alapérték ~45 nappal az első zárás után a teljes platform
         | forgalmát leállította volna — fizetési információ nélkül.
         */
        'unsettled' => [],
    ],
];
