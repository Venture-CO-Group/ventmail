<?php

declare(strict_types=1);

/*
 | A STATIKUS TERMÉKOLDAL (apex) szövegei. A kulcs-teljességet a LocaleCompletenessTest őrzi.
 */

return [
    'meta' => [
        'title' => 'ventMail — the Venture family\'s email infrastructure',
        'description' => 'Outbound and inbound email on one engine: transactional API and SMTP, marketing Studio, built-in deliverability safeguards. Operated in the EU.',
    ],
    'skip' => 'Skip to content',
    'nav' => [
        'login' => 'Sign in',
    ],
    'hero' => [
        'eyebrow' => 'Email infrastructure',
        'title' => 'The Venture family\'s email infrastructure',
        'lede' => 'Outbound AND inbound email on one engine: a transactional API and SMTP for developers, a marketing Studio for teams.',
    ],
    'platform' => [
        'title' => 'Platform',
        'lede' => 'Transactional and inbound email for developers and operators.',
        'items' => [
            'Messages API',
            'SMTP relay',
            'Inbound routing',
            'Logs and message trace',
            'Address validation',
            'IP pools and warmup',
            'Webhooks',
        ],
    ],
    'studio' => [
        'title' => 'Studio',
        'lede' => 'Marketing email for teams, on the same engine.',
        'items' => [
            'Audience and double opt-in',
            'Block editor + MJML',
            'Campaigns and A/B tests',
            'Customer Journey',
            'Forms and preference center',
            'Marketing analytics',
        ],
    ],
    'engine' => [
        'title' => 'One engine, two sides',
        'text' => 'A campaign runs on the same sending engine as an API call: shared domain authentication, suppression and logs.',
    ],
    'deliver' => [
        'title' => 'Deliverability built in',
        'lede' => 'Not an option but a requirement: no sending from an unverified domain.',
        'items' => [
            ['title' => 'SPF, DKIM, DMARC required', 'text' => 'Sending only starts from a verified domain.'],
            ['title' => 'One-click unsubscribe', 'text' => 'RFC 8058 unsubscribe in every marketing email.'],
            ['title' => 'Separate IP pools', 'text' => 'Transactional and marketing traffic use separate pools.'],
            ['title' => 'Suppression', 'text' => 'No email goes to bounced or unsubscribed addresses.'],
            ['title' => 'PreSendGate', 'text' => 'Every send, including through the SMTP relay, passes the same six gates.'],
            ['title' => 'Signed webhooks', 'text' => 'HMAC-SHA256 signatures, retries and replay for every event.'],
        ],
    ],
    'eu' => [
        'title' => 'Operated in the EU',
        'text' => 'The service and its data are operated in the European Union.',
    ],
    'footer' => [
        'nav' => 'Legal information',
        'terms' => 'Terms of use',
        'privacy' => 'Privacy policy',
        'cookies' => 'Cookie settings',
    ],
];
