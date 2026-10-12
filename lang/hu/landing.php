<?php

declare(strict_types=1);

/*
 | A STATIKUS TERMÉKOLDAL (apex) szövegei. A kulcs-teljességet a LocaleCompletenessTest őrzi.
 */

return [
    'meta' => [
        'title' => 'ventMail — a Venture család e-mail infrastruktúrája',
        'description' => 'Kimenő és bejövő e-mail egy motoron: tranzakciós API és SMTP, marketing Studio, beépített kézbesíthetőségi védelem. EU-ban üzemel.',
    ],
    'skip' => 'Ugrás a tartalomra',
    'nav' => [
        'login' => 'Belépés',
        'docs' => 'API dokumentáció',
    ],
    'hero' => [
        'eyebrow' => 'E-mail infrastruktúra',
        'title' => 'A Venture család e-mail infrastruktúrája',
        'lede' => 'Kimenő ÉS bejövő levelezés egy motoron: tranzakciós API és SMTP fejlesztőknek, marketing Studio csapatoknak.',
    ],
    'platform' => [
        'title' => 'Platform',
        'lede' => 'Tranzakciós és bejövő levelezés fejlesztőknek és üzemeltetőknek.',
        'items' => [
            'Messages API',
            'SMTP relay',
            'Bejövő útválasztás (inbound routing)',
            'Napló és üzenet-trace',
            'Címellenőrzés',
            'IP-poolok és warmup',
            'Webhookok',
        ],
    ],
    'studio' => [
        'title' => 'Studio',
        'lede' => 'Marketing levelezés csapatoknak, ugyanazon a motoron.',
        'items' => [
            'Közönség és double opt-in',
            'Block editor + MJML',
            'Kampányok és A/B teszt',
            'Customer Journey',
            'Űrlapok és preference center',
            'Marketing analitika',
        ],
    ],
    'engine' => [
        'title' => 'Egy motor, két oldal',
        'text' => 'A kampány ugyanazon a küldőmotoron megy, mint az API-hívás: közös domain-hitelesítés, suppression és napló.',
    ],
    'deliver' => [
        'title' => 'Kézbesíthetőség alapból',
        'lede' => 'Nem opció, hanem feltétel: verifikálatlan domainről nincs küldés.',
        'items' => [
            ['title' => 'SPF, DKIM, DMARC kötelező', 'text' => 'A küldés csak ellenőrzött domainről indul.'],
            ['title' => 'One-click leiratkozás', 'text' => 'RFC 8058 szerinti leiratkozás minden marketing levélben.'],
            ['title' => 'Külön IP-pool', 'text' => 'A tranzakciós és a marketing forgalom külön poolon megy.'],
            ['title' => 'Suppression', 'text' => 'Visszapattanó és leiratkozott címekre nem megy levél.'],
            ['title' => 'PreSendGate', 'text' => 'Minden küldés, az SMTP relayen át is, ugyanazon a hat kapun megy át.'],
        ],
    ],
    'eu' => [
        'title' => 'EU-ban üzemel',
        'text' => 'A szolgáltatás és az adatok az Európai Unióban üzemelnek.',
    ],
    'footer' => [
        'nav' => 'Jogi információk',
        'terms' => 'Felhasználási feltételek',
        'privacy' => 'Adatkezelési tájékoztató',
    ],
];
