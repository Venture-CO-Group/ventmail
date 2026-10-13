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
        // XLSX csomópont-plafonok (a munkalap-XML `<` jeleinek száma): összesen, és az ELSŐ adatsor
        // előtt. A felmérés kérésen belül fut, a `<row>` nélküli csomópont-áradat pedig a
        // feldolgozónak percekbe kerülne (mérve: 52 M csomópont ≈ 50 mp).
        'xlsx_max_nodes' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_NODES', 100_000_000),
        'xlsx_max_nodes_before_data' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_NODES_BEFORE_DATA', 2_000_000),
        // A többi, az openspout által TELJESEN beolvasott XML-rész (sharedStrings, styles, workbook…)
        // csomópont-plafonja: a sharedStrings legfeljebb ennyi, a többi (meta) kevesebb.
        'xlsx_max_part_nodes' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_PART_NODES', 8_000_000),
        'xlsx_max_meta_nodes' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_META_NODES', 1_000_000),
        // A háttér-felmérés belső időkerete. FIGYELEM: az openspout XML-előtöltése (a
        // sharedStrings teljes beolvasása) közben ez a keret NEM érvényesül (nincs hova ellenőrizni);
        // a VALÓS határ a job 60 mp-es timeoutja (worker-kill → `failed()` → takarítás), és a
        // csomópont-plafonok, amelyek a drága fájlt a kérésben, olcsón elutasítják.
        'inspect_job_budget_seconds' => (int) env('AUDIENCE_IMPORT_INSPECT_JOB_BUDGET', 50),
        // A felmérés-sor ELLENŐRZÉSEI (D71): egyidejű felmérések plafonja workspace-enként,
        // tulajdonosonként (user) és globálisan; a „foglalt” válasz a queue-mélység
        // védelme (a várakozó felmérések száma nem nőhet korlátlanul). A számláló TTL-je a
        // lemaradt (elveszett) job maradványát takarítja.
        'inspect_max_per_workspace' => (int) env('AUDIENCE_IMPORT_INSPECT_MAX_WS', 1),
        'inspect_max_per_user' => (int) env('AUDIENCE_IMPORT_INSPECT_MAX_USER', 1),
        'inspect_max_global' => (int) env('AUDIENCE_IMPORT_INSPECT_MAX_GLOBAL', 8),
        'inspect_gate_ttl_seconds' => (int) env('AUDIENCE_IMPORT_INSPECT_GATE_TTL', 600),
        'xlsx_max_entries' => (int) env('AUDIENCE_IMPORT_XLSX_MAX_ENTRIES', 500),
        'manual_max_rows' => (int) env('AUDIENCE_IMPORT_MANUAL_MAX_ROWS', 20_000),
        // Egy fizikai sor (és egy CSV-rekord egy olvasása) legfeljebb ennyi bájt: sor-határ nélkül
        // egy újsor nélküli 100 MB-os „sor” egyben a memóriába kerülne.
        'max_line_bytes' => (int) env('AUDIENCE_IMPORT_MAX_LINE_BYTES', 1_048_576),
        // A feltöltés utáni felmérés (zip-ellenőrzés, sorszámlálás) KÉRÉSEN BELÜL fut: a fejléc és a
        // minta azonnal kell a képernyőnek. Ezért időkerete van, és a php.ini `max_execution_time`-ja
        // ennél legalább 30 mp-cel nagyobb (lásd `ImportConfigConsistencyTest`).
        'inspect_budget_seconds' => (int) env('AUDIENCE_IMPORT_INSPECT_BUDGET', 25),
        // Workspace-enként egyszerre futó előnézet + import (previewing/queued/processing).
        'max_concurrent' => (int) env('AUDIENCE_IMPORT_MAX_CONCURRENT', 2),
        // Egyéni mezők száma workspace-enként.
        'max_fields' => (int) env('AUDIENCE_IMPORT_MAX_FIELDS', 100),
        'chunk' => (int) env('AUDIENCE_IMPORT_CHUNK', 1000),
        'retention_days' => (int) env('AUDIENCE_IMPORT_RETENTION_DAYS', 7),
    ],
];
