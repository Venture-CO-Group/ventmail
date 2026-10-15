<?php

declare(strict_types=1);

/*
 | ventSec-kötés (VentSec modul): csatolmány-szkennelés (Scan API) és biztonsági események
 | (VSES ingest). A ventMail itt CSAK fogyasztó: a tokent a ventSSO adja (client_credentials),
 | az ítéletet a ventSec írja alá, mi csak ellenőrizzük. Minden érték env-ből, titok nélkül a
 | repóban; az üres alapérték = nincs bekötve (a szkenner fail-closed `unavailable`, az
 | eseménykibocsátás KI).
 */

return [
    // --- m2m token a ventSSO-tól (client_credentials)
    // Üres token_url: az OIDC-discovery `token_endpoint`-ja (OIDC_PROVIDER_URL).
    'token_url' => env('VENTSEC_TOKEN_URL', ''),
    'client_id' => env('VENTSEC_CLIENT_ID', ''),
    'client_secret' => env('VENTSEC_CLIENT_SECRET', ''),
    'resource' => env('VENTSEC_RESOURCE', 'https://sec.ventureco.app'),
    'scope' => env('VENTSEC_SCOPE', 'ventsec.scan ventsec.ingest'),

    // --- Scan API (INBOUND_SCANNER=ventsec)
    'scan_url' => rtrim((string) env('VENTSEC_SCAN_URL', ''), '/'),
    'scan_profile' => 'mail-inbound',
    'scan_timeout' => (int) env('VENTSEC_SCAN_TIMEOUT', 30),
    // A szinkron Scan API plafonja (25 MB): fölötte a ventSec a /jobs útra utal, mi nem küldünk.
    'scan_max_bytes' => 26_214_400,
    // Az ítélet-aláíró kulcsok dokumentuma; üresen a scan_url + /.well-known/...
    'keys_url' => env('VENTSEC_KEYS_URL', ''),
    'keys_cache_ttl' => (int) env('VENTSEC_KEYS_CACHE_TTL', 300),

    // --- VSES események (ventMail → ventSec ingest). Alapból KI.
    'events' => [
        'enabled' => (bool) env('VENTSEC_EVENTS_ENABLED', false),
        'url' => rtrim((string) env('VENTSEC_INGEST_URL', ''), '/'),
        // A forrás HMAC-titka (a ventSec adja ki); szövegként, nem hex-dekódolva használjuk.
        'source_secret' => env('VENTSEC_INGEST_SECRET', ''),
        'instance' => env('VENTSEC_SOURCE_INSTANCE', ''),
        'timeout' => (int) env('VENTSEC_INGEST_TIMEOUT', 10),
    ],
];
