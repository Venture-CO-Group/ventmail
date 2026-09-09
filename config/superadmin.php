<?php

declare(strict_types=1);

return [
    /*
     | ÜGYELETI RIASZTÁSI CSATORNA (C2 · 88. kritérium).
     |
     | SOHA nem e-mail: a riasztás tárgya épp az, hogy a küldésünk nem működik (a saját
     | küldő IP-nk listán van). A cél egy webhook — PagerDuty, Opsgenie, Slack-bridge —,
     | és a titok az env-ből jön, nem az adatbázisból: az ügyeleti csatornának egy
     | adatbázis-kiesés alatt IS működnie kell, amikor épp riasztani kell.
     |
     | Ha nincs beállítva, a riasztás `error` szintű naplósort ír: egy nem konfigurált
     | csatorna a legrosszabb állapot — a rendszer azt hiszi, riasztott.
     */
    'oncall' => [
        'url' => env('SUPERADMIN_ONCALL_URL', ''),
        'secret' => env('SUPERADMIN_ONCALL_SECRET', ''),
    ],
];
