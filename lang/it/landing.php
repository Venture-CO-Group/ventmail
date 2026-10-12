<?php

declare(strict_types=1);

/*
 | A STATIKUS TERMÉKOLDAL (apex) szövegei. A kulcs-teljességet a LocaleCompletenessTest őrzi.
 */

return [
    'meta' => [
        'title' => 'ventMail — l\'infrastruttura e-mail della famiglia Venture',
        'description' => 'E-mail in uscita e in entrata su un unico motore: API transazionale e SMTP, Studio marketing, protezioni di deliverability integrate. Operativo nell\'UE.',
    ],
    'skip' => 'Vai al contenuto',
    'nav' => [
        'login' => 'Accedi',
    ],
    'hero' => [
        'eyebrow' => 'Infrastruttura e-mail',
        'title' => 'L\'infrastruttura e-mail della famiglia Venture',
        'lede' => 'E-mail in uscita E in entrata su un unico motore: API transazionale e SMTP per gli sviluppatori, Studio marketing per i team.',
    ],
    'platform' => [
        'title' => 'Platform',
        'lede' => 'E-mail transazionale e in entrata per sviluppatori e operatori.',
        'items' => [
            'Messages API',
            'SMTP relay',
            'Instradamento in entrata (inbound)',
            'Log e trace dei messaggi',
            'Verifica degli indirizzi',
            'IP pool e warmup',
            'Webhook',
        ],
    ],
    'studio' => [
        'title' => 'Studio',
        'lede' => 'E-mail marketing per i team, sullo stesso motore.',
        'items' => [
            'Pubblico e double opt-in',
            'Block editor + MJML',
            'Campagne e test A/B',
            'Customer Journey',
            'Moduli e preference center',
            'Analytics di marketing',
        ],
    ],
    'engine' => [
        'title' => 'Un motore, due lati',
        'text' => 'Una campagna gira sullo stesso motore di invio di una chiamata API: autenticazione del dominio, suppression e log condivisi.',
    ],
    'deliver' => [
        'title' => 'Deliverability di serie',
        'lede' => 'Non un\'opzione ma un requisito: nessun invio da un dominio non verificato.',
        'items' => [
            ['title' => 'SPF, DKIM, DMARC obbligatori', 'text' => 'L\'invio parte solo da un dominio verificato.'],
            ['title' => 'Disiscrizione con un clic', 'text' => 'Disiscrizione secondo RFC 8058 in ogni e-mail di marketing.'],
            ['title' => 'IP pool separati', 'text' => 'Il traffico transazionale e quello marketing usano pool separati.'],
            ['title' => 'Suppression', 'text' => 'Nessuna e-mail verso indirizzi respinti o disiscritti.'],
            ['title' => 'PreSendGate', 'text' => 'Ogni invio, anche tramite SMTP relay, passa dalle stesse sei porte.'],
            ['title' => 'Webhook firmati', 'text' => 'Firma HMAC-SHA256, nuovi tentativi e replay per ogni evento.'],
        ],
    ],
    'eu' => [
        'title' => 'Operativo nell\'UE',
        'text' => 'Il servizio e i suoi dati sono operati nell\'Unione Europea.',
    ],
    'footer' => [
        'nav' => 'Informazioni legali',
        'terms' => 'Termini di utilizzo',
        'privacy' => 'Informativa sulla privacy',
    ],
];
