<?php

declare(strict_types=1);

return [
    /*
     | BLOCKLIST-MONITOR (43. kritérium).
     |
     | A küldő IP vagy domain feketelistára kerülése az a hiba, amit MI veszünk észre utoljára
     | — az ügyfél előbb: nála hirtelen senki nem kapja meg a levelet. A monitor nem javít,
     | hanem SZÓL: a javítás (delisting kérelem, IP-csere, forgalom-visszafogás) emberi döntés.
     */
    'blocklists' => [
        'enabled' => (bool) env('DELIVERABILITY_BLOCKLIST_ENABLED', true),
        // A napló nem PII, de VÉGTELENÜL nő: óránként `IP × zóna` sor. A régi sorokat a
        // trend kedvéért tartjuk, aztán elengedjük (`deliverability:purge`).
        'retention_days' => (int) env('DELIVERABILITY_BLOCKLIST_RETENTION_DAYS', 180),
        // A leggyakrabban használt, ingyenes DNSBL-ek. A lista bővíthető; a lekérdezés
        // ugyanaz a minta minden szolgáltatónál (fordított IP + a zóna neve).
        // A lista SZŰK és szándékosan az: a `dnsbl.sorbs.net` 2024-ben megszűnt, a
        // `b.barracudacentral.org` pedig regisztrált lekérdező IP-t igényel — mindkettőt
        // óránként hiába kérdeznénk. A lekérdezés mintája minden szolgáltatónál ugyanaz
        // (fordított IP + a zóna neve), tehát a lista bővíthető.
        'zones' => [
            'zen.spamhaus.org',
            'bl.spamcop.net',
        ],
    ],

    /*
     | DMARC RUA (44. kritérium).
     |
     | A jelentés a FOGADÓKTÓL jön, és az egyetlen forrás, amiből megtudjuk, hogy a mi
     | nevünkben KI küld. Az ismeretlen forrás vagy egy elfelejtett saját rendszer, vagy egy
     | hamisító — mindkettő azonnali figyelmet érdemel.
     */
    'dmarc' => [
        // Egy jelentés felső mérete kicsomagolva. A gzip-elt XML tíz-húszszorosára nő.
        'max_report_bytes' => (int) env('DELIVERABILITY_DMARC_MAX_BYTES', 52_428_800),
        'retention_days' => (int) env('DELIVERABILITY_DMARC_RETENTION_DAYS', 90),
    ],

    /*
     | SEED / INBOX PLACEMENT (45. kritérium).
     */
    'seed' => [
        'enabled' => (bool) env('DELIVERABILITY_SEED_ENABLED', false),
        'retention_days' => (int) env('DELIVERABILITY_SEED_RETENTION_DAYS', 90),
    ],

    /*
     | KÜLDÉS ELŐTTI SPAM-PONTSZÁM (46. kritérium).
     |
     | Szabály-alapú, NEM statisztikai: az ügyfélnek meg kell tudnia mondani, MIÉRT kapott
     | pontot, és mit tegyen ellene. Egy fekete doboz „0.7"-e semmit nem ér.
     */
    'spam_score' => [
        // A rövidítő-szolgáltatások: a fogadók régóta gyanúsnak veszik őket, mert elrejtik a
        // valódi célt — és a spamszűrők nem tudják ellenőrizni, hova visznek.
        'shorteners' => [
            'bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly',
            'rebrand.ly', 'cutt.ly', 'shorturl.at', 'rb.gy', 'tiny.cc',
        ],
        // A DOMAIN-feketelista: a küldő IP-je lehet makulátlan, ha a levélben lévő link
        // listás domainre mutat, a fogadók a LEVELET minősítik spamnek. Üres értékkel a
        // szabály kikapcsol (a lekérdezés DNS-t igényel).
        'domain_blocklist_zone' => env('DELIVERABILITY_DOMAIN_BLOCKLIST_ZONE', 'dbl.spamhaus.org'),
        // A küszöb, ami fölött figyelmeztetünk. NEM blokkol: a döntés a tenanté.
        'warn_at' => (float) env('DELIVERABILITY_SPAM_WARN_AT', 5.0),
    ],

    /*
     | MTA-STS és TLS-RPT (48. kritérium).
     */
    'mta_sts' => [
        'mode' => env('DELIVERABILITY_MTA_STS_MODE', 'testing'),
        'max_age' => (int) env('DELIVERABILITY_MTA_STS_MAX_AGE', 604_800),
    ],
];
