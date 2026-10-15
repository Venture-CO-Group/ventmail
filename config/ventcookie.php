<?php

declare(strict_types=1);

/*
 | ventCookie consent loader a ventMail saját publikus oldalain. A consent a ventCookie-é;
 | itt csak a betöltő snippet beállításai vannak (mind publikus érték, nem titok).
 | Üres domain_token = semmi nem renderelődik.
 */
return [
    'domain_token' => (string) env('VENTCOOKIE_DOMAIN_TOKEN', ''),
    'loader_src' => (string) env('VENTCOOKIE_LOADER_SRC', 'https://cookie.ventureco.app/cdn/v1/loader.js'),
    'loader_sri' => (string) env('VENTCOOKIE_LOADER_SRI', ''),
    'site_verification' => (string) env('VENTCOOKIE_SITE_VERIFICATION', ''),
];
