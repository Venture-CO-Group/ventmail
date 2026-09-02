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
];
