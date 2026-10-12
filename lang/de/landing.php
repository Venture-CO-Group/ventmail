<?php

declare(strict_types=1);

/*
 | A STATIKUS TERMÉKOLDAL (apex) szövegei. A kulcs-teljességet a LocaleCompletenessTest őrzi.
 */

return [
    'meta' => [
        'title' => 'ventMail — die E-Mail-Infrastruktur der Venture-Familie',
        'description' => 'Ausgehende und eingehende E-Mails auf einer Engine: Transaktions-API und SMTP, Marketing-Studio, integrierter Zustellbarkeitsschutz. Betrieb in der EU.',
    ],
    'skip' => 'Zum Inhalt springen',
    'nav' => [
        'login' => 'Anmelden',
    ],
    'hero' => [
        'eyebrow' => 'E-Mail-Infrastruktur',
        'title' => 'Die E‑Mail-Infrastruktur der Venture-Familie',
        'lede' => 'Ausgehende UND eingehende E-Mails auf einer Engine: Transaktions-API und SMTP für Entwickler, Marketing-Studio für Teams.',
    ],
    'platform' => [
        'title' => 'Platform',
        'lede' => 'Transaktions- und Eingangs-E-Mail für Entwickler und Betreiber.',
        'items' => [
            'Messages API',
            'SMTP-Relay',
            'Eingehendes Routing (Inbound)',
            'Protokoll und Nachrichten-Trace',
            'Adressprüfung',
            'IP-Pools und Warmup',
            'Webhooks',
        ],
    ],
    'studio' => [
        'title' => 'Studio',
        'lede' => 'Marketing-E-Mail für Teams, auf derselben Engine.',
        'items' => [
            'Zielgruppe und Double-Opt-in',
            'Block-Editor + MJML',
            'Kampagnen und A/B-Tests',
            'Customer Journey',
            'Formulare und Preference Center',
            'Marketing-Analytik',
        ],
    ],
    'engine' => [
        'title' => 'Eine Engine, zwei Seiten',
        'text' => 'Eine Kampagne läuft auf derselben Versand-Engine wie ein API-Aufruf: gemeinsame Domain-Authentifizierung, Suppression und Protokolle.',
    ],
    'deliver' => [
        'title' => 'Zustellbarkeit von Anfang an',
        'lede' => 'Keine Option, sondern Voraussetzung: kein Versand von einer nicht verifizierten Domain.',
        'items' => [
            ['title' => 'SPF, DKIM, DMARC verpflichtend', 'text' => 'Der Versand startet nur von einer verifizierten Domain.'],
            ['title' => 'One-Click-Abmeldung', 'text' => 'Abmeldung nach RFC 8058 in jeder Marketing-E-Mail.'],
            ['title' => 'Getrennte IP-Pools', 'text' => 'Transaktions- und Marketing-Verkehr laufen über getrennte Pools.'],
            ['title' => 'Suppression', 'text' => 'An abgewiesene oder abgemeldete Adressen geht keine E-Mail.'],
            ['title' => 'PreSendGate', 'text' => 'Jeder Versand, auch über das SMTP-Relay, durchläuft dieselben sechs Tore.'],
            ['title' => 'Signierte Webhooks', 'text' => 'HMAC-SHA256-Signatur, Wiederholung und Replay für jedes Ereignis.'],
        ],
    ],
    'eu' => [
        'title' => 'Betrieb in der EU',
        'text' => 'Der Dienst und seine Daten werden in der Europäischen Union betrieben.',
    ],
    'footer' => [
        'nav' => 'Rechtliche Hinweise',
        'terms' => 'Nutzungsbedingungen',
        'privacy' => 'Datenschutzerklärung',
    ],
];
