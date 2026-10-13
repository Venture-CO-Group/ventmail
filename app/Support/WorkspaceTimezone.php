<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;

/**
 * A WORKSPACE IDŐZÓNÁJA (v1.1 · 5. modul) — a kampány-ütemezés és a dashboard napi bontása.
 *
 * Forrás-sorrend: a workspace beállítása (`settings.timezone`) → a bejelentkezett felhasználó
 * profil-időzónája → UTC. A workspace-szintű beállító felület még nincs (HUMAN-QUEUE: a
 * workspace-beállítások oldala); addig a felhasználó saját időzónája a jó közelítés, és a
 * kampány a választott zónát RÖGZÍTI (`campaigns.timezone`), tehát egy későbbi változás nem
 * csúsztatja el a már ütemezett küldést.
 *
 * Csak érvényes IANA-azonosító jöhet vissza: egy elgépelt érték a `Carbon`-ban kivételt dobna.
 */
final class WorkspaceTimezone
{
    public static function for(?Workspace $workspace, ?User $user = null): string
    {
        $candidates = [
            ($workspace?->settings ?? [])['timezone'] ?? null,
            $user?->timezone ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }
}
