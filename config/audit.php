<?php

declare(strict_types=1);

/*
 | Audit- és pszeudonimizálási beállítások.
 |
 | A pepper KÜLÖN titok, NEM az app kulcsa: az `APP_KEY` rotációja különben minden korábbi
 | `ip_hash` / `email_hash` / API-kulcs-hash értéket egy csapásra érvénytelenítene.
 | Nincs fallback: üres pepperrel a HMAC csendben gyenge lenne, ezért a KeyedHash dob.
 |
 | Rotáció: az új értéket az `AUDIT_PEPPER`-be, a régit átfedéses időre az
 | `AUDIT_PREVIOUS_PEPPER`-be. Az ellenőrzés mindkettőt elfogadja, az új írás csak az újat.
 */

return [
    'pepper' => env('AUDIT_PEPPER', ''),
    'previous_pepper' => env('AUDIT_PREVIOUS_PEPPER', ''),

    // Az audit-payload felső korlátja: egy MIME-fejlécekkel teli context különben
    // korlátlan PII-t öntene egy törölhetetlen táblába.
    'max_payload_bytes' => (int) env('AUDIT_MAX_PAYLOAD_BYTES', 65536),
    'max_depth' => (int) env('AUDIT_MAX_DEPTH', 8),

    /*
     | AUDIT-RETENCIÓ napban.
     |
     | Az audit-sor MEGVÁLTOZTATHATATLAN, de nem örök: a „soha nem törölhető" a
     | GDPR 5(1)(e) tárolási korlátozásával nem összeegyeztethető, és a `context` szabad
     | szöveges mezőin (támogatói jegyzet, ügy-leírás) olyan PII is bekerülhet, amit
     | minta-alapú redakció nem ismer fel. A retenció ezért a MÁSODIK védelmi vonal az
     | `AuditLogger` pszeudonimizálása mögött: ami mégis bement, az lejár.
     |
     | Két év az alap: egy incidens-vizsgálat, egy adóhatósági kérdés és egy éves audit is
     | belefér, viszont a „minden nyom örökre" állapot megszűnik.
     |
     | ALSÓ HATÁR: 365 nap, és ezt NEM a config őrzi, hanem a DB-trigger. Egy elgépelt
     | `AUDIT_RETENTION_DAYS=1` különben egyetlen ütemezett futással kitörölné a friss
     | naplót — épp azt, ami egy folyamatban lévő incidensnél a legértékesebb.
     */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),
];
