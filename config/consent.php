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
];
