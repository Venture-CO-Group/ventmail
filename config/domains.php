<?php

declare(strict_types=1);

/*
 | Domain-hitelesítés (spec 4.).
 |
 | Ezek az értékek jelennek meg a wizardban ÉS ezekre ellenőriz a DNS-check — a kettő
 | ugyanabból a forrásból dolgozik, hogy ne csúszhassanak szét.
 */

return [
    /*
     | A DNS-feloldás forrása: `system` (éles) vagy `fake` (teszt/dev).
     |
     | A fake élesben TILOS és hangosan elszáll: a `publish()` metódusa miatt a
     | domain-tulajdon bizonyítása alkalmazás-kódból felülírható lenne.
     |
     | Bizalmi modell — DOKUMENTÁLT KORLÁT: a rendszer resolvere nem ad DNSSEC-jelzést,
     | tehát cache-poisoning ellen ma nem véd. A DoH/DoT resolver `AD`-bit ellenőrzéssel
     | az ops-sáv feladata (halasztott), és amíg nincs, a hitelesítés bizalmi szintje ennyi.
     */
    'resolver' => env('DOMAINS_RESOLVER', 'fake'),

    'spf_include' => env('DOMAINS_SPF_INCLUDE', 'spf.ventmail.app'),
    'dmarc_policy' => env('DOMAINS_DMARC_POLICY', 'none'),
    'dmarc_rua' => env('DOMAINS_DMARC_RUA', 'dmarc@ventmail.app'),
    'tracking_subdomain' => env('DOMAINS_TRACKING_SUBDOMAIN', 'email'),
    'tracking_target' => env('DOMAINS_TRACKING_TARGET', 'track.ventmail.app'),

    // A DKIM-rotáció átfedése: ennyi ideig marad érvényes az előző selector.
    'dkim_rotation_overlap_days' => (int) env('DOMAINS_DKIM_OVERLAP_DAYS', 7),
];
