<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;

/**
 * Minden képesség-ellenőrzés belépési pontja.
 *
 * A szerep az AKTUÁLIS workspace tagságából jön (`workspace_members`), nem a ventSSO
 * tokenből és nem egy másik workspace tagságából. Tagság nélkül semmi nem engedélyezett —
 * EGY kivétellel: az aktív támogatói nézet (impersonation) OLVASÁSI jogot ad arra az egy
 * workspace-re, amire nyílt. Az írást két független réteg tiltja (middleware + modell-őr).
 */
final class WorkspaceGate
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly Impersonation $impersonation,
    ) {}

    public function allows(User $user, string $ability): bool
    {
        if (! $this->context->hasWorkspace()) {
            return false;
        }

        /*
         | AKTÍV TÁMOGATÓI NÉZET: a platform-admin LÁTJA a tenant képernyőit.
         |
         | Enélkül a 82. kritérium fele használhatatlan lenne: az impersonation megadja a
         | kontextust, de tagság híján MINDEN képesség hamis — a támogatás tehát bejut a
         | tenant nézetébe, és ott 403-at kap minden képernyőn. Egy nézet, amin semmi nem
         | látszik, nem nézet.
         |
         | Az ÍRÁST nem ez engedi vagy tiltja: azt az `EnforceImpersonation` middleware
         | (minden nem biztonságos HTTP-metódus) és az `ImpersonationReadOnlyGuard`
         | (minden tenant-modell mentése) zárja — két, egymástól független rétegben. Ez a
         | döntés tehát OLVASÁSI jog, nem írási.
         |
         | A hatókör szűk: kizárólag arra a workspace-re szól, amelyikre a munkamenet nyílt.
         */
        $session = $this->impersonation->active($user);

        if ($session !== null && (int) $session->workspace_id === $this->context->workspaceId()) {
            return true;
        }

        $member = WorkspaceMember::query()
            ->where('workspace_id', $this->context->workspaceId())
            ->where('user_id', $user->getKey())
            ->first();

        if ($member === null) {
            return false;
        }

        return AbilityMap::allows($member->roles(), $ability);
    }
}
