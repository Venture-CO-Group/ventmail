<?php

declare(strict_types=1);

return [
    /*
     | A GDPR-törlés utáni helyettesítő domain (52. kritérium).
     |
     | A törölt kontakt sora MEGMARAD (a listatagságok és a kampány-statisztikák különben
     | visszamenőleg megváltoznának), a címe viszont felismerhetetlen. A `.invalid` TLD-t az
     | RFC 2606 pontosan erre tartja fenn: garantáltan nem létezik, tehát ha egy hiba miatt
     | mégis küldeni próbálnánk rá, az azonnal és hangosan elhasal — nem egy idegen
     | postafiókban landol.
     */
    'erased_domain' => env('AUDIENCE_ERASED_DOMAIN', 'erased.invalid'),

    /*
     | ŰRLAP-BEKÜLDÉS (54. kritérium): a time-trap alsó határa másodpercben, ha az űrlap nem
     | ad sajátot. Három másodperc alatt ember nem tölt ki űrlapot; a bot igen.
     */
    'form_min_seconds' => (int) env('AUDIENCE_FORM_MIN_SECONDS', 3),

    /*
     | A ventOS-szinkron (56. kritérium) kötegméret. A szinkron kétirányú és idempotens; a
     | köteg azért kell, hogy egy milliós lista ne egyetlen tranzakcióban menjen.
     */
    'sync_batch' => (int) env('AUDIENCE_SYNC_BATCH', 500),

    /*
     | KONTAKT-IMPORT (v1.1). Minden határ env-ből állítható, de az alapérték EGYSZERRE véd
     | és elég: egy 300 000 soros CSV ~25 MB.
     |
     |  - `max_bytes`: a feltöltött fájl plafonja (a webszerver/PHP `upload_max_filesize` ennél
     |    szigorúbb is lehet — a kisebb nyer);
     |  - `max_rows` / `max_columns` / `max_cell_length`: a streamelt olvasás határai. A
     |    cellahossz-vágás azért biztonságos, mert egy 254 oktettnél hosszabb e-mail úgyis
     |    érvénytelen, a szabad mezőknek pedig nincs 2000 karakternél hosszabb valós értéke;
     |  - `xlsx_max_uncompressed_bytes` / `xlsx_max_ratio`: zip-bomba őr — a kitömörített méretet
     |    a tárolt zip-fejlécből olvassuk, KITÖMÖRÍTÉS ELŐTT;
     |  - `chunk`: hány soronként megy egy upsert (és egy haladás-frissítés);
     |  - `retention_days`: a hibariport és a félbehagyott feltöltések megőrzése.
     */
    'import' => [
        'disk' => env('AUDIENCE_IMPORT_DISK', 'local'),
        /*
         | A queue-kapcsolat: az import PERCEK-ÓRÁK hosszú job. A Redis-sor `retry_after`-je (alap:
         | 90 mp) alatt a futó jobot a sor „elveszettnek" hinné és újra kiadná — ezért saját,
         | hosszú `retry_after`-es kapcsolat (`redis_imports`), ha a sor Redis. Más sor-
         | meghajtónál (sync a tesztben) az alapkapcsolat marad.
         */
        'connection' => env('AUDIENCE_IMPORT_QUEUE_CONNECTION', env('QUEUE_CONNECTION') === 'redis' ? 'redis_imports' : null),
        'max_bytes' => (int) env('AUDIENCE_IMPORT_MAX_MB', 100) * 1024 * 1024,
        'max_rows' => (int) env('AUDIENCE_IMPORT_MAX_ROWS', 1_000_000),
        'max_columns' => (int) env('AUDIENCE_IMPORT_MAX_COLUMNS', 100),
        'max_cell_length' => (int) env('AUDIENCE_IMPORT_MAX_CELL', 2000),
        'xlsx_max_uncompressed_bytes' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_MB', 1024) * 1024 * 1024,
        'xlsx_max_ratio' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_RATIO', 200),
        'xlsx_max_entries' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_ENTRIES', 500),
        'manual_max_rows' => (int) env('AUDIENCE_IMPORT_MANUAL_MAX_ROWS', 20_000),
        'chunk' => (int) env('AUDIENCE_IMPORT_CHUNK', 1000),
        'retention_days' => (int) env('AUDIENCE_IMPORT_RETENTION_DAYS', 7),
    ],
];
