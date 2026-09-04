<?php

declare(strict_types=1);

return [
    /*
     | A beérkezett levél retenciója. IDEGEN TARTALOM: nem mi írtuk, és nem a mi
     | jogalapunkon tároljuk — a rövid alapérték szándékos (spec 10.4).
     */
    'retention_days' => (int) env('INBOUND_RETENTION_DAYS', 3),

    /*
     | A LEVÉL felső mérethatára a fogadáskor. A `SIZE` kiterjesztésben is ezt hirdetjük,
     | tehát a küldő már a `MAIL FROM`-nál megtudja — nem tölt fel 25 MB-ot azért, hogy a
     | végén utasítsuk el.
     */
    'max_message_bytes' => (int) env('INBOUND_MAX_MESSAGE_BYTES', 26_214_400),

    /*
     | Az MX-oldal SMTP-korlátai. Ismeretlen küldő, hitelesítés nélkül: minden korlát nélkül
     | hagyott dimenzió egy DoS-vektor.
     */
    'smtp' => [
        'max_command_bytes' => (int) env('INBOUND_MAX_COMMAND_BYTES', 4096),
        'max_commands' => (int) env('INBOUND_MAX_COMMANDS', 100),
        'max_transactions' => (int) env('INBOUND_MAX_TRANSACTIONS', 20),
        'max_recipients' => (int) env('INBOUND_MAX_RECIPIENTS', 100),
        // Címfelderítés elleni fék: aki találgatással térképezi fel, melyik cím létezik
        // nálunk, annak a session véget ér.
        'max_rejected_recipients' => (int) env('INBOUND_MAX_REJECTED_RECIPIENTS', 10),
    ],

    /*
     | A PARSER korlátai. Ismeretlen eredetű MIME: minden korlát nélkül hagyott dimenzió egy
     | DoS-vektor.
     */
    'parser' => [
        // Beágyazott `message/rfc822` és `multipart` mélység. Egy 1000 szintű matrjoska
        // rekurzív parsernél stack overflow, iteratívnál is percekig tartó munka.
        'max_depth' => (int) env('INBOUND_PARSER_MAX_DEPTH', 20),
        // Hány rész összesen. A „ezer üres rész" ugyanúgy DoS, mint egy mély fa.
        'max_parts' => (int) env('INBOUND_PARSER_MAX_PARTS', 500),
        // Falióra-korlát a parse-ra. A sandbox ennél előbb megszakít.
        'max_seconds' => (int) env('INBOUND_PARSER_MAX_SECONDS', 15),
    ],

    'attachments' => [
        // PLATFORM-plafon. A plan ennél kisebbet mondhat, nagyobbat nem.
        'max_bytes' => (int) env('INBOUND_ATTACHMENT_MAX_BYTES', 26_214_400),
        // ZIP-BOMBA: a kicsomagolt/tömörített arány felső határa.
        'max_compression_ratio' => (int) env('INBOUND_MAX_COMPRESSION_RATIO', 150),
        // A kicsomagolt méret abszolút plafonja — az arány önmagában nem elég egy nagy
        // archívumnál.
        'max_uncompressed_bytes' => (int) env('INBOUND_MAX_UNCOMPRESSED_BYTES', 268_435_456),
    ],

    // A nyers levél és a csatolmányok tárolója (Wasabi EU).
    'disk' => env('INBOUND_DISK', 'inbound'),

    // A csatolmány-letöltési URL élettartama. Idegen tartalom: nem adunk rá örök linket.
    'download_url_seconds' => (int) env('INBOUND_DOWNLOAD_URL_SECONDS', 900),

    // A SAJÁT `Authentication-Results` azonosítónk. Csak az ezzel kezdődő fejlécet olvassuk
    // sajátunkként — a küldő által beírt `dkim=pass` hazugság.
    'authserv_id' => env('INBOUND_AUTHSERV_ID', 'mx.ventmail.app'),

    'clamav' => [
        'dsn' => env('INBOUND_CLAMAV_DSN', 'tcp://clamav:3310'),
        'timeout' => (int) env('INBOUND_CLAMAV_TIMEOUT', 10),
    ],

    /*
     | A `forward(url)` HMAC-aláírt POST-ja: ugyanaz a séma, mint a webhookoké (A3), mert az
     | integrátornak nem kell két aláírás-ellenőrzőt írnia.
     */
    'forward' => [
        'max_attempts' => (int) env('INBOUND_FORWARD_MAX_ATTEMPTS', 6),
        'timeout_seconds' => (int) env('INBOUND_FORWARD_TIMEOUT', 10),
    ],
];
