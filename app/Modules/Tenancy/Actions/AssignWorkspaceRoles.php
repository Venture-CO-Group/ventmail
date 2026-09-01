<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Policies\AbilityMap;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * A tagság-kiosztás EGYETLEN engedélyezett útja.
 *
 * Két invariánst kényszerít ki:
 * 1. „Nem adhatsz olyan szerepet, amivel magad nem rendelkezel." Enélkül az `admin`
 *    kiosztaná magának az `owner`-t, és a szándékos korlátai (billing, törlés) elvesznének.
 * 2. A szerep csak a hat ismert érték egyike lehet — a `roles` jsonb-be tetszőleges
 *    sztring kerülhetne, amit az AbilityMap csak olvasáskor szűrne.
 */
final class AssignWorkspaceRoles
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /** @param list<string> $roles */
    public function __invoke(User $target, array $roles): WorkspaceMember
    {
        $actor = Auth::user();

        if ($actor === null || ! Gate::forUser($actor)->allows('member.manage')) {
            throw new AuthorizationException('Nincs jogosultságod tagságot kezelni.');
        }

        $validated = array_map(
            fn (string $role) => Role::tryFromClaim($role)
                ?? throw new InvalidArgumentException("Ismeretlen szerep: {$role}"),
            $roles
        );

        $actorRoles = $this->rolesOf($actor);

        // KÉPESSÉG-alapú korlát, nem szerep-egyenlőség: gyengébb szerepet lehet osztani,
        // erősebbet nem. Az `admin` így adhat `developer`-t, de `owner`-t nem (annak van
        // `billing.manage`-e és `workspace.delete`-je, amivel az admin nem rendelkezik).
        foreach ($validated as $role) {
            $granted = AbilityMap::abilitiesOf($role);
            $missing = array_values(array_filter(
                $granted,
                fn (string $ability) => ! AbilityMap::allows($actorRoles, $ability)
            ));

            if ($missing !== []) {
                throw new AuthorizationException(sprintf(
                    'Nem oszthatsz ki nálad erősebb szerepet (%s): hiányzó jogaid: %s',
                    $role->value,
                    implode(', ', $missing)
                ));
            }
        }

        return WorkspaceMember::updateOrCreate(
            ['workspace_id' => $this->context->workspaceId(), 'user_id' => $target->getKey()],
            ['roles' => array_map(fn (Role $role) => $role->value, $validated)],
        );
    }

    /** @return list<string> */
    private function rolesOf(User $user): array
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $this->context->workspaceId())
            ->where('user_id', $user->getKey())
            ->first()?->roles() ?? [];
    }
}
