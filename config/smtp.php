<?php

declare(strict_types=1);

/*
 | SMTP submission (ADR 0001).
 |
 | A limitek NEM ízlés kérdései: mindegyik egy nevesített visszaélést fog meg (parancs-özön,
 | lassú-loris, memória-kimerítés, jelszó-brute-force). A számok az ADR D4 táblájából jönnek.
 */

return [
    'enabled' => (bool) env('SMTP_SUBMISSION_ENABLED', true),

    // A listener CSAK ezeket a portokat kötheti. A 25 SZÁNDÉKOSAN nincs itt: az a bejövő
    // (MX) oldal dolga (A4), más parserrel és más bizalmi modellel.
    'allowed_ports' => [587, 465, 2587, 2465],

    'hostname' => env('SMTP_HOSTNAME', 'smtp.ventmail.app'),

    'tls' => [
        'certificate' => env('SMTP_TLS_CERT', ''),
        'private_key' => env('SMTP_TLS_KEY', ''),
    ],

    'limits' => [
        'max_command_bytes' => 512,          // RFC 5321 §4.5.3.1
        'max_auth_bytes' => 12288,           // a base64 PLAIN/LOGIN nem fér 512-be
        'max_data_line_bytes' => 1000,
        'max_message_bytes' => (int) env('SENDING_MAX_MIME_BYTES', 26_214_400),
        'max_commands' => 100,
        'max_transactions' => 50,
        'max_commands_before_auth' => 10,
        'max_auth_failures' => 3,
        'command_timeout_seconds' => 300,    // RFC 5321 §4.5.3.2 minimum
        'data_timeout_seconds' => 600,
        // ABSZOLÚT életkor: a tétlenségi korlátot egy percenként egy bájtot küldő kliens
        // örökre újraindítaná. Bőven a `data_timeout` fölött, hogy a nagy levél beférjen.
        'session_timeout_seconds' => 1200,
        'max_connections' => 200,

        // Az ÖSSZES kapcsolat pufferelt levéltörzse együtt. A kapcsolatonkénti 25 MB
        // önmagában 200 kapcsolatnál ~5 GB elvi plafont adna: egy hitelesített tenant így
        // az összes többi submissionjét kivinné.
        //
        // A szám a PHP `memory_limit`-hez van kötve, nem légből kapott: a törzs
        // `.=`-összefűzése realloc-duplázással jár, tehát a valós csúcs a könyvelt bájtok
        // KÉTSZERESE. A listener indulásnál ellenőrzi, hogy belefér-e — ha nem, EL SEM
        // INDUL (ahogy a hiányzó TLS-tanúsítványnál is). Enélkül a plafon sosem sült volna
        // el: a PHP OOM-fatal ELŐBB jött, azt pedig try/catch nem fogja.
        'max_buffered_bytes' => (int) env('SMTP_MAX_BUFFERED_BYTES', 134_217_728),
        'max_connections_per_ip' => 10,
    ],

    // HITELESÍTŐ-szintű küldési sebesség (tulajdonosi döntés: 60/perc az alapérték).
    // A workspace-szintű 300/perc megmarad fölötte: egy elszabadult legacy integráció ne
    // egye meg a tenant teljes keretét. Hitelesítőnként felülírható
    // (`smtp_credentials.rate_limit_per_minute`).
    'rate_limit' => [
        'per_credential_per_minute' => (int) env('SMTP_CREDENTIAL_RATE_LIMIT', 60),
    ],

    // PROXY protocol (v1 és v2). LB mögött KÖTELEZŐ: enélkül minden kapcsolat a balancer
    // IP-jéről érkezik, tehát a per-IP fékek egyetlen közös vödörré válnak — nem védenek,
    // viszont egyetlen rossz szomszéd mindenkit kizár. Bekapcsolva a fejléc KÖTELEZŐ:
    // hiányában bontunk (különben a kliens maga hamisíthatná a forrás-IP-t).
    'proxy_protocol' => [
        'enabled' => (bool) env('SMTP_PROXY_PROTOCOL', false),

        // CSAK EZEKTŐL a forrásoktól fogadjuk el a fejlécet (CIDR vagy pontos IP).
        //
        // Enélkül a feature NETTÓ ROMLÁS: aki közvetlenül eléri a portot (LB megkerülése),
        // kapcsolatonként új, hamis forrás-IP-t hazudna — és mivel a brute-force számláló
        // IP-kulcsú, a tiltás SOHA nem telne be. A PROXY protocol specifikációja is ezt
        // követeli: a fejléc csak allowlistázott forrásról hihető.
        'trusted_sources' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SMTP_PROXY_TRUSTED_SOURCES', ''))
        ))),
    ],

    // Brute-force fék a hitelesítőn — processzek között osztott (Redis).
    'auth_throttle' => [
        'failures_per_window' => 10,
        'window_seconds' => 900,
        'ban_threshold' => 25,
        'ban_seconds' => 3600,
    ],
];
