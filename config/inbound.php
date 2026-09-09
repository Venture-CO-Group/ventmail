<?php

declare(strict_types=1);

return [
    // A bejövő fogadás fő kapcsolója. Alapból KI: MX-rekord nélkül úgysincs mit fogadni, és
    // egy véletlenül nyitva hagyott 25-ös port a legrosszabb fajta meglepetés.
    'enabled' => (bool) env('INBOUND_ENABLED', false),

    'hostname' => env('INBOUND_HOSTNAME', 'mx.ventmail.app'),

    // Az MX-rekord CÉLJA, amit az ügyféltől kérünk. A `VerifyInboundMx` ezt keresi a
    // domain MX-rekordjai között — ez a bejövő fogadás bizalmi horgonya.
    'mx_target' => env('INBOUND_MX_TARGET', 'mx.ventmail.app'),

    // Hány szabály értékelődhet ki EGY levélre. A bejövő forgalom forró útja: korlát nélkül
    // minden aktív szabály betöltődne levelenként.
    'max_routes' => (int) env('INBOUND_MAX_ROUTES', 200),

    // A listener KIZÁRÓLAG ezeken indulhat. A submission portjai (587/465) SZÁNDÉKOSAN nincsenek
    // köztük: egy elgépelt `--port` nem cserélheti fel a két bizalmi modellt.
    'allowed_ports' => array_map('intval', explode(',', (string) env('INBOUND_ALLOWED_PORTS', '25,2525'))),

    /*
     | A HEALTH-PRÓBA CÉLJA (C2 · 86. kritérium): hol keresse a cluster-health az MX-fogadót.
     |
     | Miért külön config: a listener MÁS konténerben fut (`inbound-mx`, `--port=2525`), az
     | `allowed_ports` pedig az ELFOGADHATÓ portok listája, nem a futó listener címe. A
     | próba korábban `127.0.0.1` + `allowed_ports[0]` (= 25) volt, ami az app-konténerből
     | SOHA nem érhető el — mérve: `Connection refused` mindkét porton, tehát a komponens
     | bekapcsolt inbound mellett állandóan `down`-t adott, és lerontotta az egész riportot.
     */
    'health' => [
        'host' => env('INBOUND_HEALTH_HOST', 'inbound-mx'),
        'port' => (int) env('INBOUND_HEALTH_PORT', 2525),
    ],

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
    /*
     | A TRANSZPORT limitjei — a submissiontől FÜGGETLENÜL.
     |
     | A bejövő oldal elé nem a mi terheléselosztónk áll, hanem a világ összes MTA-ja: a
     | PROXY protokoll itt alapból KI, és a per-IP keret is bőségesebb (egy nagy szolgáltató
     | sok kapcsolatot nyit egyszerre).
     */
    'limits' => [
        'max_auth_bytes' => (int) env('INBOUND_MAX_COMMAND_BYTES', 4096),
        'max_buffered_bytes' => (int) env('INBOUND_MAX_BUFFERED_BYTES', 134_217_728),
        'max_connections_per_ip' => (int) env('INBOUND_MAX_CONNECTIONS_PER_IP', 50),
        'max_connections' => (int) env('INBOUND_MAX_CONNECTIONS', 400),
        // A küldő MTA-k türelmesek, de nem végtelenül: a nyitva felejtett kapcsolat a mi
        // erőforrásunkat köti.
        'command_timeout_seconds' => (int) env('INBOUND_COMMAND_TIMEOUT', 60),
        'data_timeout_seconds' => (int) env('INBOUND_DATA_TIMEOUT', 600),
        'session_timeout_seconds' => (int) env('INBOUND_SESSION_TIMEOUT', 900),
    ],

    'proxy_protocol' => [
        'enabled' => (bool) env('INBOUND_PROXY_PROTOCOL', false),
        'trusted_sources' => array_filter(explode(',', (string) env('INBOUND_PROXY_TRUSTED_SOURCES', ''))),
    ],

    'smtp' => [
        'max_command_bytes' => (int) env('INBOUND_MAX_COMMAND_BYTES', 4096),
        // A parancs-plafon a CÍMZETT-plafon fölött: 100 `RCPT` + `EHLO` + `MAIL` + `DATA`
        // már 103 parancs, tehát 100-as plafonnal a session a címzett-limit ELŐTT bomlana —
        // a két szám összehangolatlansága egy nagy terjesztési lista kézbesítését ölné meg.
        'max_commands' => (int) env('INBOUND_MAX_COMMANDS', 160),
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
        // MEMÓRIA. Az idő önmagában nem elég: a `memory_limit` túllépése FATAL ERROR, amit
        // a hívó `catch (Throwable)` ága NEM fog el — a worker meghalna.
        // A worker `memory_limit`-jének BIZTONSÁGOS hányada. A 25 MB-os levél dekódolása
        // önmagában ~100 MB — az inbound worker ezért 512 MB-tal indul (compose).
        'max_memory_bytes' => (int) env('INBOUND_PARSER_MAX_MEMORY_BYTES', 268_435_456),
        // A fejléc-blokk önmagában is lehet 25 MB; a `preg_split` egy ekkorára sok
        // százmegányi tömböt épít.
        'max_header_bytes' => (int) env('INBOUND_PARSER_MAX_HEADER_BYTES', 262_144),
    ],

    'attachments' => [
        // PLATFORM-plafon. A plan ennél kisebbet mondhat, nagyobbat nem.
        'max_bytes' => (int) env('INBOUND_ATTACHMENT_MAX_BYTES', 26_214_400),
        // ZIP-BOMBA: a kicsomagolt/tömörített arány felső határa.
        'max_compression_ratio' => (int) env('INBOUND_MAX_COMPRESSION_RATIO', 150),
        // Az ARÁNY-korlát alatta nem érvényes: egy pár megabájtra kibomló fájl fizikailag
        // ártalmatlan, akármilyen az aránya. Enélkül a sok üres sort tartalmazó, exportált
        // táblázatok (`.xlsx`) tévesen bombának minősülnének.
        'ratio_floor_bytes' => (int) env('INBOUND_RATIO_FLOOR_BYTES', 8_388_608),
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
