<?php

declare(strict_types=1);

return [
    /*
     | DOUBLE OPT-IN (50. kritérium).
     |
     | A megerősítő link élettartama. Van lejárat — szemben a leiratkozó tokennel —, és a
     | különbség szándékos: a leiratkozás régi levélből is legitim, a megerősítés nem. Egy
     | hónapokkal későbbi kattintás nem bizonyítja, hogy a felhasználó MOST is akarja, és a
     | GDPR szerinti hozzájárulás „konkrét és tájékozott".
     |
     | Az alapérték egy hét: elég ahhoz, hogy a nyaraló felhasználó is megerősítsen, és nem
     | annyi, hogy a szándék elavuljon.
     */
    'confirmation_ttl_hours' => (int) env('CONSENT_CONFIRMATION_TTL_HOURS', 168),

    /*
     | A MEGERŐSÍTŐ LEVÉL feladója és tárgya. A levél TRANZAKCIÓS: a megerősítés kérése nem
     | marketing, és a kapu 3. kapuja (consent) nem is engedné ki marketingként — hiszen épp
     | a consent megszerzéséről szól.
     */
    'confirmation' => [
        'subject' => env('CONSENT_CONFIRMATION_SUBJECT', 'Kérjük, erősítsd meg a feliratkozásod'),
    ],

    /*
     | A PREFERENCE CENTER linkjének élettartama (55. kritérium). Hosszabb, mint a
     | megerősítőé: a címzett a levélben lévő linket hetekkel később is joggal használja a
     | beállításai módosítására.
     */
    'preference_ttl_hours' => (int) env('CONSENT_PREFERENCE_TTL_HOURS', 2160),

    /*
     | A ventCookie E-MAIL-CONSENT FŐKÖNYV (FO3): a double opt-in bizonyítéka egy hash-láncolt,
     | append-only főkönyvbe is bekerül (POST /api/internal/v1/email-consent). A helyi consent
     | marad a hivatalos; a főkönyv-hiba soha nem blokkol. Alapból `fake` (nincs hívás): a
     | `ventcookie` meghajtóhoz mind az öt érték kell, élesben hiányosan a boot elbukik.
     | A token és a subject_key titok: env-ből, soha a repóból.
     */
    'ledger' => [
        'driver' => env('CONSENT_LEDGER_DRIVER', 'fake'),
        'url' => rtrim((string) env('CONSENT_LEDGER_URL', ''), '/'),
        'token' => env('CONSENT_LEDGER_TOKEN', ''),
        'site_id' => env('CONSENT_LEDGER_SITE_ID', ''),
        // A HMAC-kulcs az alany-hivatkozáshoz: HMAC-SHA256(kisbetűs, vágott e-mail, kulcs).
        'subject_key' => env('CONSENT_LEDGER_SUBJECT_KEY', ''),
        'timeout' => (int) env('CONSENT_LEDGER_TIMEOUT', 10),
    ],
];
