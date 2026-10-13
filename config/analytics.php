<?php

declare(strict_types=1);

/*
 | v1.1 · 8. modul — ANALITIKA: PDF-riport (Gotenberg sidecar, D7/D238) és workspace-branding.
 |
 | A renderer élesben `gotenberg` (belső, kimenő forgalom nélküli háló: `http://gotenberg:3000`),
 | fejlesztésben és tesztben `fake` (determinisztikus minimális PDF) — a Chromium-sidecar nélkül
 | is végigjárható a teljes riport-folyamat.
 */
return [
    'pdf' => [
        // Üres érték (`ANALYTICS_PDF_RENDERER=` a mintából) = az alapértelmezés. Élesben/stagingen a
        // `fake` TILOS, ismeretlen érték mindenhol hiba (AnalyticsServiceProvider, D263).
        //
        // A környezet UGYANABBÓL a forrásból jön, mint az alkalmazásé (`config/app.php`:
        // `env('APP_ENV', 'production')`): beállítatlan APP_ENV = éles = gotenberg. Korábban a
        // null `fake`-et adott, miközben az app élesnek tekintette magát — a `composer install`
        // utáni `package:discover` a D263 ellenőrzésen halt meg (CI, PR #42).
        'renderer' => env('ANALYTICS_PDF_RENDERER') ?: (in_array(env('APP_ENV', 'production'), ['production', 'staging'], true) ? 'gotenberg' : 'fake'),
        'gotenberg_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        // A hívható Gotenberg-hosztok (SSRF-őr: a renderer csak belső sidecarra küld tenant-adatot).
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOTENBERG_ALLOWED_HOSTS', 'gotenberg,localhost,127.0.0.1'))))),
        'timeout' => (int) env('ANALYTICS_PDF_TIMEOUT', 60),
        // A bemenő HTML és a kimenő PDF felső mérete (bájt).
        'max_html_bytes' => 5 * 1024 * 1024,
        'max_pdf_bytes' => 20 * 1024 * 1024,
    ],

    'reports' => [
        // A privát riport-diszk (a D227 megosztott volume-on: `storage/app/private/reports`).
        'disk' => env('ANALYTICS_REPORT_DISK', 'local'),
        'ttl_days' => 7,
        // Az aláírt letöltő-link élettartama (perc) — kérésre generált, nem tárolt (D240).
        'link_minutes' => 10,
        'queue' => 'reports',
        'connection' => env('ANALYTICS_REPORT_QUEUE_CONNECTION', env('QUEUE_CONNECTION') === 'redis' ? 'redis_reports' : null),
        // Csonkolás a riportban (a felület a teljes képet adja).
        'top_links' => 25,
        'max_campaigns' => 50,
        'max_journeys' => 20,
        'section_budget_ms' => 5000,
    ],

    'branding' => [
        'disk' => env('ANALYTICS_BRANDING_DISK', 'local'),
        'logo_max_bytes' => 1024 * 1024,
        'logo_min_px' => 64,
        'logo_max_px' => 2000,
        'logo_max_ratio' => 8,
    ],
];
