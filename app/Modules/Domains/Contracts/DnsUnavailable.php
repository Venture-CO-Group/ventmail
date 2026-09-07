<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contracts;

use RuntimeException;

/**
 * A DNS-LEKÉRDEZÉS nem futott le — és ez NEM ugyanaz, mint a „nincs ilyen rekord".
 *
 * A különbségen egy platform-szintű kiesés múlik. A `records()` korábban minden hibára üres
 * tömböt adott, tehát a SERVFAIL, az időtúllépés és az NXDOMAIN megkülönböztethetetlen volt.
 * A címellenőrzés ebből „nincs MX"-et olvasott ki, az 1.0 kockázatot, és a PreSendGate
 * MINDEN levelet elutasított — mérve: egy DNS-kimaradás alatt a teljes platform küldése áll,
 * és a hatás a kockázat-cache miatt EGY ÓRÁVAL TÚLÉLI a kiváltó okot.
 *
 * A modul a szondánál példásan elválasztja a „nem tudjuk"-ot a „rossz"-tól (`skipped` vs
 * `unknown`); a DNS-nél ugyanez kell.
 */
final class DnsUnavailable extends RuntimeException {}
