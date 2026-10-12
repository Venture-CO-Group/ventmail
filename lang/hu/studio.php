<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'A szegmens mentve.',
        'inUse' => 'A szegmenst egy még ki nem ment kampány használja — előbb onnan vedd ki.',
        'deleted' => 'A szegmens törölve.',
        'invalid' => 'Érvénytelen szegmens-definíció.',
    ],
    'tags' => [
        'created' => 'A címke létrejött.',
        'renamed' => 'A címke átnevezve.',
        'merged' => ':count kontakt átkerült a(z) „:name” címkére.',
        'usedByJourney' => 'A címkét journey használja (:names) — az összevonás előtt vedd ki onnan.',
        'inUse' => 'A címke használatban van (:names) — előbb szüntesd meg a hivatkozást vagy vond össze.',
        'deleted' => 'A címke törölve.',
        'bulkTagged' => ':count kontakt megkapta a(z) „:name” címkét.',
        'bulkUntagged' => 'A(z) „:name” címke lekerült :count kontaktról.',
        'nameRequired' => 'Adj nevet az új címkének.',
    ],
];
