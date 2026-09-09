<?php

declare(strict_types=1);

/*
 | MAGYAR — a termék elsődleges nyelve.
 |
 | A KULCS-SZERKEZET az angol referenciával AZONOS (C2 · 89. kritérium). A hiányzó VAGY
 | extra kulcs TESZT-BUKÁS: a Laravel a hiányzó kulcs helyén a KULCSOT írja ki, vagy a
 | fallback nyelv szövegét — és egy angol mondat egy magyar felületen olyan hiba, amit
 | senki nem jelent be.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'A Venture család e-mail infrastruktúrája',
        'workspace_switched' => 'Workspace átváltva.',
        'locale_switched' => 'A nyelv beállítva.',
    ],
    'apiKeys' => [
        'revoked' => 'A kulcs visszavonva.',
    ],
    'smtp' => [
        'revoked' => 'A hitelesítő visszavonva.',
    ],
    'suppressions' => [
        'added' => 'A cím felvéve a tiltólistára.',
        'removed' => 'A tiltás feloldva.',
        'missing' => 'Ez a cím nem szerepel a tiltólistán.',
        'irrevocable' => 'A panasz- és leiratkozás-alapú tiltás nem oldható fel: az az érintett akarata.',
    ],
    'webhooks' => [
        'deleted' => 'A végpont törölve.',
        'replayed' => 'A kézbesítés újra sorba állítva.',
        'alreadyDelivered' => 'Ez a kézbesítés már sikeres volt.',
    ],
    'audience' => [
        'listCreated' => 'A lista létrejött.',
    ],
    'templates' => [
        'saved' => 'A sablon mentve.',
    ],
    'campaigns' => [
        'saved' => 'A kampány mentve.',
        'schedule' => 'A kampány ütemezve.',
        'unschedule' => 'Az ütemezés visszavonva.',
        'start' => 'A kampány elindult.',
        'pause' => 'A kampány szüneteltetve.',
        'resume' => 'A kampány folytatódik.',
    ],
    'journeys' => [
        'activated' => 'A journey aktív.',
        'paused' => 'A journey szünetel.',
    ],
    'gate' => [
        'domain_unverified' => 'A küldő domain nincs hitelesítve.',
        'suppressed' => 'A címzett a tiltólistán van.',
        'no_consent' => 'Nincs megerősített marketing hozzájárulás erre a címre.',
        'quota_exceeded' => 'A küldési kvóta kimerült, vagy a fiók fel van függesztve.',
        'rate_limited' => 'Elérted az ütemkorlátot; próbáld újra rövidesen.',
        'validation_risk' => 'A cím nem ment át a címellenőrzési kockázati vizsgálaton.',
        'invalid_request' => 'A kérés nem felel meg a küldési szabályoknak (címzett, csatorna vagy fejléc).',
        'gate_error' => 'Egy ellenőrzés nem tudott dönteni; a küldés biztonsági okból elmaradt.',
        'rejected' => 'A küldést a PreSendGate elutasította.',
    ],
    'campaign' => [
        'status' => [
            'draft' => 'Vázlat',
            'scheduled' => 'Ütemezve',
            'sending' => 'Küldés folyamatban',
            'paused' => 'Szüneteltetve',
            'sent' => 'Elküldve',
            'canceled' => 'Visszavonva',
        ],
        'recipients' => 'Címzettek',
        'open_rate' => 'Nyitási arány',
        'click_rate' => 'Klikk-arány',
    ],
    'unsubscribe' => [
        'heading' => 'Leiratkoztál',
        'body' => 'Ettől a feladótól nem kapsz több marketing levelet.',
        'preferences' => 'Levélbeállítások kezelése',
        'resubscribe' => 'Feliratkozom újra',
    ],
    'preferences' => [
        'heading' => 'Levélbeállítások',
        'intro' => 'Válaszd ki, milyen leveleket szeretnél kapni.',
        'save' => 'Beállítások mentése',
        'saved' => 'A beállításaidat elmentettük.',
        'unsubscribe_all' => 'Leiratkozom mindenről',
    ],
    'consent' => [
        'confirm_heading' => 'Erősítsd meg a feliratkozásod',
        'confirm_body' => 'Kérjük, igazold, hogy szeretnél leveleket kapni ettől a feladótól.',
        'confirmed' => 'Köszönjük, a feliratkozásod megerősítve.',
        'expired' => 'Ez a megerősítő link lejárt. Kérjük, iratkozz fel újra.',
    ],
    'platform' => [
        'impersonation_active' => 'Támogatói nézet aktív — csak olvasás.',
        'health' => [
            'healthy' => 'Rendben',
            'degraded' => 'Romlott',
            'down' => 'Kiesett',
            'unknown' => 'Ismeretlen',
        ],
        'impersonationEnded' => 'A támogatói nézet lezárva.',
        'overrideGranted' => 'Az override megadva.',
        'overrideRevoked' => 'Az override visszavonva.',
        'suppressionAdded' => 'A globális tiltás felvéve.',
        'suppressionRemoved' => 'A globális tiltás feloldva.',
    ],
];
