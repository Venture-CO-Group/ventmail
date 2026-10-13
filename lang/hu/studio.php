<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'A szegmens mentve.',
        'inUse' => 'A szegmenst egy még ki nem ment kampány használja — előbb onnan vedd ki.',
        'deleted' => 'A szegmens törölve.',
        'invalid' => 'Érvénytelen szegmens-definíció.',
        'tooExpensive' => 'A szegmens túl költséges (5 másodpercnél tovább futna). Szűkítsd a feltételeket.',
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
    'checklist' => [
        'notReady' => 'A kampány még nem küldhető — hiányzik: :blockers.',
        'canceled' => 'A kampány megszakítva.',
        'unscheduled' => 'A módosítás után a kampány nem teljesíti a checklistet, ezért az ütemezést visszavontuk — javítsd, és ütemezd újra.',
        'unscheduledNoPublish' => 'A kampányt vázlatba vettük: ütemezett kampányt csak közzétételi joggal lehet módosítani. Egy közzétevő ütemezheti újra.',
    ],
    'hosted' => [
        'email' => 'E-mail-cím',
        'firstName' => 'Keresztnév',
        'lastName' => 'Vezetéknév',
        'trap' => 'Ezt a mezőt hagyd üresen',
        'subscribe' => 'Feliratkozom',
        'doiNotice' => 'A feliratkozás után megerősítő levelet küldünk — megerősítés nélkül nem küldünk semmit.',
        'thanksTitle' => 'Köszönjük!',
        'thanksBody' => 'Elküldtünk egy megerősítő levelet. Kérjük, kattints a benne lévő linkre.',
        'thanksNoMail' => 'Megerősítés nélkül nem küldünk semmit.',
        'confirmTitle' => 'Feliratkozás megerősítése',
        'confirmBody' => 'Erősítsd meg, hogy szeretnél levelet kapni tőlünk.',
        'confirmAction' => 'Igen, megerősítem',
        'confirmIgnore' => 'Ha nem te kérted, egyszerűen zárd be ezt az oldalt — megerősítés nélkül nem küldünk semmit.',
        'confirmedTitle' => 'Feliratkozás megerősítve',
        'confirmedBody' => 'Köszönjük! A feliratkozásod aktív.',
        'confirmedHint' => 'A beállításaidat bármikor módosíthatod, és minden levelünk alján találsz leiratkozó linket.',
        'savedTitle' => 'Beállítások mentve',
        'savedBody' => 'A kérésedet feldolgoztuk.',
        'preferencesTitle' => 'Levélbeállítások',
        'preferencesInvalid' => 'Ez a link már nem érvényes. Kérj újat a levelünk alján lévő linkkel.',
        'preferencesLists' => 'Miről szeretnél levelet kapni?',
        'preferencesProfile' => 'Az adataid',
        'preferencesSave' => 'Beállítások mentése',
        'unsubscribeAll' => 'Leiratkozom minden marketing levélről',
        'transactionalNote' => 'A számlákat és a fiókodhoz tartozó értesítéseket ez nem érinti.',
    ],
    'forms' => [
        'unknownField' => 'Ismeretlen mező: :key. Csak a név-mezők és a közönség egyéni mezői választhatók.',
    ],
    'insights' => [
        'tooExpensive' => 'A közönség-statisztika most túl sokáig tartana — próbáld újra később.',
    ],
    'fields' => [
        'updated' => 'A mező beállítása mentve.',
    ],
];
