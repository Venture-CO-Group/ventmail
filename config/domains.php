<?php

declare(strict_types=1);

/*
 | Domain-hitelesítés (spec 4.).
 |
 | Ezek az értékek jelennek meg a wizardban ÉS ezekre ellenőriz a DNS-check — a kettő
 | ugyanabból a forrásból dolgozik, hogy ne csúszhassanak szét.
 */

return [
    'spf_include' => env('DOMAINS_SPF_INCLUDE', 'spf.ventmail.app'),
    'dmarc_policy' => env('DOMAINS_DMARC_POLICY', 'none'),
    'dmarc_rua' => env('DOMAINS_DMARC_RUA', 'dmarc@ventmail.app'),
    'tracking_subdomain' => env('DOMAINS_TRACKING_SUBDOMAIN', 'email'),
    'tracking_target' => env('DOMAINS_TRACKING_TARGET', 'track.ventmail.app'),

    // A DKIM-rotáció átfedése: ennyi ideig marad érvényes az előző selector.
    'dkim_rotation_overlap_days' => (int) env('DOMAINS_DKIM_OVERLAP_DAYS', 7),
];
