<?php

declare(strict_types=1);

return [
    /*
     | SUB-ACCOUNTOK (spec 3.2).
     |
     | A darabszám-plafon nem műszaki korlát: az al-fiók létrehozása ingyenes, a fogyása
     | viszont a PARENT konszolidált számlájára megy. Plafon nélkül egy elszabadult
     | integráció (vagy egy visszaélő ügynökség-fiók) korlátlan workspace-t hozna létre,
     | mindegyiket saját kvótával, ugyanarra a számlára.
     */
    'sub_accounts' => [
        'max' => (int) env('TENANCY_SUB_ACCOUNT_MAX', 50),
    ],

    /*
     | MEGHÍVÓK.
     |
     | A `retention_days` a BEVÁLTOTT és LEJÁRT sorokra vonatkozik: a meghívó olyan ember
     | e-mail-címét tárolja, aki lehet, hogy sosem lesz felhasználó, és aki nem járult
     | hozzá semmihez. A tagság keletkezésének bizonyítéka az AUDIT-naplóban marad (ott a
     | saját retenció szabályoz), ezért ez a tábla nyugodtan takarítható.
     */
    'invitations' => [
        'ttl_days' => (int) env('TENANCY_INVITATION_TTL_DAYS', 14),
        'retention_days' => (int) env('TENANCY_INVITATION_RETENTION_DAYS', 90),
    ],
];
