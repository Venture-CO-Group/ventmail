<?php

declare(strict_types=1);

return [
    /*
     | SZEREPCÍMEK — nem személyek, hanem funkciók.
     |
     | Marketing listán ezek a legrosszabb címek: nem egy ember iratkozott fel, hanem egy
     | csoportos postafiók, ahol bárki megnyomhatja a spam-gombot. A panasz viszont a MI
     | reputációnkat viszi. Nem tiltjuk — jelezzük.
     */
    'role_addresses' => [
        'abuse', 'admin', 'administrator', 'billing', 'compliance', 'contact', 'devnull',
        'ftp', 'help', 'hostmaster', 'info', 'inoc', 'ispfeedback', 'ispsupport',
        'list-request', 'list', 'maildaemon', 'marketing', 'noc', 'no-reply', 'noreply',
        'null', 'phish', 'phishing', 'postmaster', 'privacy', 'registrar', 'root',
        'sales', 'security', 'spam', 'support', 'sysadmin', 'tech', 'undisclosed-recipients',
        'unsubscribe', 'usenet', 'uucp', 'webmaster', 'www',
        // magyar megfelelők
        'ugyfelszolgalat', 'informacio', 'penzugy', 'titkarsag', 'iroda', 'rendeles',
    ],

    /*
     | ELDOBHATÓ (eldobható/ideiglenes) domainek.
     |
     | Rövid, karbantartott lista: a teljes (több tízezer soros) készlet külső adatforrás, és
     | a napi frissítése az A6 kézbesíthetőségi packeté. A minta viszont MOST is kell, mert
     | ezek a címek garantáltan bounce-olnak vagy sosem olvassák őket.
     */
    'disposable_domains' => [
        'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
        'throwawaymail.com', 'yopmail.com', 'trashmail.com', 'sharklasers.com',
        'getnada.com', 'temp-mail.org', 'dispostable.com', 'maildrop.cc',
        'fakeinbox.com', 'mailnesia.com', 'mytemp.email', 'emailondeck.com',
    ],

    /*
     | ELGÉPELÉS-JAVASLAT: a leggyakoribb szolgáltatók, amikre hasonlítani szoktak.
     |
     | Ez a modul legnagyobb üzleti értékű funkciója: egy `gmial.com` cím a listán ÖRÖKRE
     | bounce-ol, de egy kattintással javítható — ha egyáltalán szólunk róla.
     */
    'common_domains' => [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com',
        'yahoo.com', 'yahoo.co.uk', 'icloud.com', 'me.com', 'aol.com', 'proton.me',
        'protonmail.com', 'gmx.net', 'web.de', 'mail.com', 'zoho.com',
        // magyar szolgáltatók
        'freemail.hu', 'citromail.hu', 'indamail.hu', 't-online.hu', 'vipmail.hu',
    ],

    /*
     | A SZONDA fékei (40. kritérium).
     |
     | Minden szonda egy IDEGEN szerverhez intézett kérés a MI IP-nkről. Aki száz címet
     | ellenőriz ugyanazon a domainen, az nem küldhet száz szondát — a fogadó ezt (joggal)
     | címfelderítésnek veszi, és blokkolja az IP-nket. A cache és a per-domain keret
     | EGYÜTT véd: a cache az ismétlődést, a keret a szélességet.
     */
    'probe' => [
        'enabled' => (bool) env('VALIDATION_PROBE_ENABLED', true),
        // Hány VALÓDI szonda mehet ki egy domainre percenként.
        'per_domain_per_minute' => (int) env('VALIDATION_PROBE_PER_DOMAIN', 5),
        // Az EREDMÉNY cache-ideje. A postafiókok léte lassan változik; a napos cache a
        // szondák számát nagyságrenddel csökkenti.
        'cache_seconds' => (int) env('VALIDATION_PROBE_CACHE_SECONDS', 86_400),
        'timeout_seconds' => (int) env('VALIDATION_PROBE_TIMEOUT', 10),
    ],

    /*
     | A KOCKÁZATI PONTSZÁM súlyai.
     |
     | Egyetlen jel sem dönt önmagában (a szintaxis és a hiányzó MX kivételével — azok
     | ténykérdések). A súlyok összeadódnak, és a minősítés a küszöbökből jön.
     */
    'weights' => [
        'role' => 0.25,
        'disposable' => 0.60,
        'catch_all' => 0.35,
        'no_mx' => 1.00,
        'syntax' => 1.00,
        'probe_rejected' => 1.00,
        'probe_unknown' => 0.20,
        // A javaslat HINT, nem vád: a `gmial.com`-nak lehet valódi, működő MX-e
        // (domain-squatting), és olyankor a levél kézbesítődik. A súly ezért a `risky`
        // küszöb ALATT van — önmagában nem minősít át egy működő domaint, más jellel együtt
        // viszont számít.
        'typo' => 0.15,
    ],

    /*
     | A MINŐSÍTÉS küszöbei.
     |
     | A `risky` sáv szándékosan széles: a „valószínűleg jó, de nem biztos" a leggyakoribb
     | valóság, és az ügyfélnek EZT kell látnia, nem egy hamis bizonyosságot.
     */
    'thresholds' => [
        'undeliverable' => (float) env('VALIDATION_UNDELIVERABLE_AT', 0.80),
        'risky' => (float) env('VALIDATION_RISKY_AT', 0.25),
    ],

    /*
     | BULK feldolgozás (41. kritérium).
     */
    'bulk' => [
        'max_rows' => (int) env('VALIDATION_BULK_MAX_ROWS', 100_000),
        'chunk' => (int) env('VALIDATION_BULK_CHUNK', 500),
        'retention_days' => (int) env('VALIDATION_BULK_RETENTION_DAYS', 30),
    ],
];
