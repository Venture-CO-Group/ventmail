<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;

/**
 * Minden képesség-ellenőrzés belépési pontja.
 *
 * A szerep az AKTUÁLIS workspace tagságából jön (`workspace_members`), nem a ventSSO
 * tokenből és nem egy másik workspace tagságából. Tagság nélkül semmi nem engedélyezett.
 */
final class WorkspaceGate
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function allows(User $user, string $ability): bool
    {
        if (! $this->context->hasWorkspace()) {
            return false;
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
