<?php

declare(strict_types=1);

/*
 | ANGOL — a REFERENCIA-nyelv (C2 · 89. kritérium).
 |
 | ## Miért az angol a referencia, ha a termék magyar cégé
 |
 | Mert a `fallback_locale` az angol, és a kulcs-teljességi tesztnek EGY forrást kell
 | tekintenie igazságnak. Ha a magyar lenne a referencia, egy magyarul hozzáadott kulcs
 | öt nyelven bukna el a teszten — ami helyes —, de egy angolul hozzáadott kulcs
 | CSENDBEN átmenne, és a magyar felületen angol szöveg jelenne meg.
 |
 | ## A HIÁNYZÓ KULCS TESZT-BUKÁS, nem futásidejű fallback
 |
 | A Laravel a hiányzó kulcs helyén a KULCSOT ÍRJA KI (`ventmail.campaign.sent`), vagy a
 | fallback nyelv szövegét. Mindkettő rosszabb, mint a hiba: egy magyar felhasználó vagy
 | egy pontot tartalmazó technikai azonosítót lát, vagy angol szöveget — és EZT SENKI NEM
 | JELENTI BE. A `LocaleCompletenessTest` ezért a hiányzó ÉS az extra kulcsot is bukásnak
 | tekinti.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'Email infrastructure for the Venture family',
    ],

    'gate' => [
        'domain_unverified' => 'The sending domain is not verified.',
        'suppressed' => 'The recipient is on the suppression list.',
        'no_consent' => 'No confirmed marketing consent for this recipient.',
        'quota_exceeded' => 'The sending quota is exhausted or the account is suspended.',
        'rate_limited' => 'Rate limit reached; try again shortly.',
        'validation_risk' => 'The address failed validation risk checks.',
        'invalid_request' => 'The request does not satisfy the sending rules (recipient, channel or header).',
        'gate_error' => 'A check could not decide; the send was withheld for safety.',
        'rejected' => 'The PreSendGate rejected the send.',
    ],

    'campaign' => [
        'status' => [
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            'sending' => 'Sending',
            'paused' => 'Paused',
            'sent' => 'Sent',
            'canceled' => 'Canceled',
        ],
        'recipients' => 'Recipients',
        'open_rate' => 'Open rate',
        'click_rate' => 'Click rate',
    ],

    'unsubscribe' => [
        'heading' => 'You have been unsubscribed',
        'body' => 'You will no longer receive marketing emails from this sender.',
        'preferences' => 'Manage your email preferences',
        'resubscribe' => 'Subscribe again',
    ],

    'preferences' => [
        'heading' => 'Email preferences',
        'intro' => 'Choose which emails you want to receive.',
        'save' => 'Save preferences',
        'saved' => 'Your preferences have been saved.',
        'unsubscribe_all' => 'Unsubscribe from everything',
    ],

    'consent' => [
        'confirm_heading' => 'Confirm your subscription',
        'confirm_body' => 'Please confirm that you want to receive emails from this sender.',
        'confirmed' => 'Thank you, your subscription is confirmed.',
        'expired' => 'This confirmation link has expired. Please sign up again.',
    ],

    'platform' => [
        'impersonation_active' => 'Support view active — read only.',
        'health' => [
            'healthy' => 'Healthy',
            'degraded' => 'Degraded',
            'down' => 'Down',
            'unknown' => 'Unknown',
        ],
    ],
];
