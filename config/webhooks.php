<?php

declare(strict_types=1);

/*
 | WEBHOOKOK — v1.1 · 7. modul review S4 (D191).
 */
return [
    /*
     | E2E-FOGADÓ: a böngésző-suite webhook-kézbesítése NEM mehet ki harmadik félhez. Ha ez
     | be van állítva (URL-prefix, pl. `https://93.184.216.34/e2e/`), az erre illeszkedő
     | kéréseket a HTTP-kliens helyben válaszolja meg (200). KIZÁRÓLAG `local`/`testing`
     | környezetben hat — élesben a beállítás figyelmen kívül marad (és naplózódik).
     | Az SSRF-őr, az aláírás és a kézbesítési állapotgép változatlanul fut.
     */
    'e2e_sink' => env('WEBHOOKS_E2E_SINK'),
];
