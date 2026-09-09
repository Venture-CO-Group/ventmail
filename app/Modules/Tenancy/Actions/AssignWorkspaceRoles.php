<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
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
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<string> $roles */
    public function __invoke(User $target, array $roles): WorkspaceMember
    {
        $roleValues = array_map(
            fn (Role $role) => $role->value,
            $this->assertGrantable($roles)
        );

        $existing = WorkspaceMember::query()
            ->where('workspace_id', $this->context->workspaceId())
            ->where('user_id', $target->getKey())
            ->first();

        // AZ UTOLSÓ TULAJDONOS nem fokozható le.
        //
        // Nem elméleti: az `owner` egyetlen kattintással `analyst`-tá tehette magát, és a
        // workspace-ben onnantól SENKI nem tudott tagot kezelni, számlázni vagy törölni —
        // a saját felületén nem volt visszaút, csak platform-adminnal.
        $this->assertOwnerRemains($existing, in_array(Role::Owner->value, $roleValues, true));

        $member = WorkspaceMember::updateOrCreate(
            ['workspace_id' => $this->context->workspaceId(), 'user_id' => $target->getKey()],
            ['roles' => $roleValues],
        );

        // Golden rule 11: a szerep-kiosztás a legprivilegizáltabb admin-művelet — enélkül
        // valaki jogot oszthatna magának úgy, hogy nyoma sem marad.
        $this->audit->log(
            'member.roles.assigned',
            $member,
            before: ['roles' => $existing?->roles() ?? []],
            after: ['roles' => $roleValues],
            context: [
                'target_user_id' => $target->getKey(),
                'target_sso_subject' => $target->sso_subject,
            ],
        );

        return $member;
    }

    /**
     * A KIOSZTHATÓSÁG ellenőrzése — a MEGHÍVÓ is ezt hívja.
     *
     * Miért nem maradt a `__invoke()` belsejében: a meghívó ugyanezt a döntést hozza meg,
     * csak KÉSŐBBRE — a szerepek a beváltáskor lépnek életbe. Ha a szabály két helyen élne,
     * a meghívó úton ki lehetne kerülni: az `admin` `owner` meghívót írna, és a szerepet a
     * beváltás adná ki, ellenőrzés nélkül.
     *
     * @param  list<string>  $roles
     * @return list<Role>
     */
    public function assertGrantable(array $roles): array
    {
        $actor = Auth::user();

        if ($actor === null || ! Gate::forUser($actor)->allows('member.manage')) {
            throw new AuthorizationException('Nincs jogosultságod tagságot kezelni.');
        }

        if ($roles === []) {
            throw new InvalidArgumentException('Legalább egy szerepet meg kell adni.');
        }

        $validated = array_map(
            fn (string $role) => Role::tryFromClaim($role)
                ?? throw new InvalidArgumentException("Ismeretlen szerep: {$role}"),
            array_values($roles)
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

        return $validated;
    }

    /**
     * Marad-e tulajdonos, ha ez a tagság elveszíti (vagy megszűnik)?
     *
     * A kérdést a HÍVÁS ELŐTT kell feltenni: utána már nem lehet megkülönböztetni, hogy
     * azért nincs owner, mert épp most vettük el, vagy mert sosem volt.
     */
    public function assertOwnerRemains(?WorkspaceMember $existing, bool $keepsOwner): void
    {
        if ($existing === null || $keepsOwner || ! in_array(Role::Owner->value, $existing->roles(), true)) {
            return;
        }

        $otherOwners = WorkspaceMember::query()
            ->where('workspace_id', $this->context->workspaceId())
            ->whereKeyNot($existing->getKey())
            ->get()
            ->contains(fn (WorkspaceMember $member) => in_array(Role::Owner->value, $member->roles(), true));

        if (! $otherOwners) {
            throw new AuthorizationException('A workspace utolsó tulajdonosa nem távolítható el és nem fokozható le.');
        }
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
