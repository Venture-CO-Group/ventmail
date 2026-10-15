<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Contracts;

/**
 * Biztonsági események a ventSec felé (VSES 1.0). Külső gazda interfész mögött; a fake teszttel
 * fedett kontraktus.
 *
 * Az `emit` a katalógus-szűrőn átmenő eseményt sorba teszi (ha a kibocsátás be van kapcsolva),
 * és SOSEM blokkol: a hívó a saját folyamatát (SMTP-hitelesítés, csatolmány-feldolgozás) nem
 * kötheti a ventSec elérhetőségéhez. Programozói hibára (ismeretlen action, nem engedélyezett
 * kontextus-kulcs) InvalidArgumentException — a hívó ezt is elnyeli.
 */
interface VentSecEvents
{
    /**
     * @param  array<string, scalar|null|list<scalar|null>>  $context  a katalógus-allowlist kulcsai
     */
    public function emit(string $action, string $outcome, array $context = []): void;

    /** Szívverés küldése (szinkron). `false`, ha KI van kapcsolva vagy nem sikerült. */
    public function heartbeat(): bool;
}
