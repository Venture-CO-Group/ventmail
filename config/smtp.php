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
        'max_connections_per_ip' => 10,
    ],

    // Brute-force fék a hitelesítőn — processzek között osztott (Redis).
    'auth_throttle' => [
        'failures_per_window' => 10,
        'window_seconds' => 900,
        'ban_threshold' => 25,
        'ban_seconds' => 3600,
    ],
];
