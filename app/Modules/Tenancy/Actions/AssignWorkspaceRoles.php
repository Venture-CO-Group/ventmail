<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
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

        // A CÉLPONT MOSTANI szerepe is számít, nem csak a kiosztandó.
        //
        // Az invariáns eddig FÉLOLDALAS volt: „nálad erősebbet nem ADHATSZ" — de nálad
        // erősebbet ELVENNI szabad volt. Mérve: az `admin` (akitől a szerep-térkép
        // szándékosan megvonja a `billing.manage`-t és a `workspace.delete`-et) egyetlen
        // kéréssel `analyst`-tá fokozta le az egyik `owner`-t. Két tulajdonosból az
        // egyiket így kiirtotta, a másikat csak az utolsó-tulajdonos védelem mentette meg
        // — vagyis a teljes átvételt egy VÉLETLEN fogta meg, nem szabály.
        $this->assertTargetNotStronger($existing);

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

        // A LEFOKOZOTT TAG DÖNTÉSE SEM ÉLHET TOVÁBB.
        //
        // A `RemoveMember` revokálja az eltávolított tag nyitott meghívóit, a lefokozás
        // viszont nem tette — pedig az az olcsóbb, gyakoribb reakció. Mérve: `admin` A
        // `admin`-meghívót ír, az owner `analyst`-ra fokozza A-t, a meghívó nyitva marad
        // `["admin"]` szereppel, a meghívott elfogadja — és `admin` tagságot kap attól,
        // akitől ezt a jogot épp elvették.
        //
        // CSAK a már nem kiosztható meghívók esnek el: egy előléptetés (több szerep) nem
        // vonja vissza a korábbi, továbbra is fedezett meghívókat.
        $this->revokeUngrantableInvitations($target, $roleValues);

        // A CHILD WORKSPACE-EK tulajdonosi köre követi a parentét (lásd a metódus
        // indoklását): enélkül a távozó kolléga megtartaná az owner-jogot minden általa
        // létrehozott al-fiókon, az új tulajdonos pedig be sem lépne oda.
        $this->syncChildOwnership($target, in_array(Role::Owner->value, $roleValues, true));

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
            throw new AuthorizationException(__('ventmail.members.notAllowed'));
        }

        if ($roles === []) {
            throw new InvalidArgumentException(__('ventmail.members.noRoles'));
        }

        $validated = array_map(
            fn (string $role) => Role::tryFromClaim($role)
                ?? throw new InvalidArgumentException(__('ventmail.members.unknownRole', ['role' => $role])),
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
                throw new AuthorizationException(__('ventmail.members.strongerRole', [
                    'role' => $role->value,
                    'missing' => implode(', ', $missing),
                ]));
            }
        }

        return $validated;
    }

    /**
     * A CÉLPONT nem lehet erősebb a hívónál.
     *
     * Ugyanaz a KÉPESSÉG-alapú összehasonlítás, mint a kiosztásnál — csak a másik
     * irányban: aki nem tudna `owner`-t ADNI, az `owner`-t ELVENNI sem tud. A szerep-nevek
     * összevetése kevés lenne: két szerep akkor is összemérhetetlen, ha a nevük más.
     */
    public function assertTargetNotStronger(?WorkspaceMember $existing): void
    {
        if ($existing === null) {
            return;
        }

        $actor = Auth::user();

        if ($actor === null) {
            throw new AuthorizationException(__('ventmail.members.notAllowed'));
        }

        // A SAJÁT tagság mindig módosítható: a lefokozásnál az utolsó-tulajdonos
        // invariáns véd, a kizárásnál pedig külön szabály (senki nem zárhatja ki magát).
        if ((int) $existing->user_id === (int) $actor->getKey()) {
            return;
        }

        $actorRoles = $this->rolesOf($actor);

        $missing = array_values(array_filter(
            $this->abilitiesOf($existing->roles()),
            fn (string $ability) => ! AbilityMap::allows($actorRoles, $ability)
        ));

        if ($missing !== []) {
            throw new AuthorizationException(__('ventmail.members.strongerTarget', [
                'missing' => implode(', ', $missing),
            ]));
        }
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function abilitiesOf(array $roles): array
    {
        $abilities = [];

        foreach ($roles as $role) {
            $case = Role::tryFromClaim($role);

            if ($case !== null) {
                $abilities = [...$abilities, ...AbilityMap::abilitiesOf($case)];
            }
        }

        return array_values(array_unique($abilities));
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
            throw new AuthorizationException(__('ventmail.members.lastOwner'));
        }
    }

    /**
     * A tag NYITOTT meghívói közül azok, amiket az ÚJ szerepeivel már nem oszthatna ki.
     *
     * @param  list<string>  $newRoles
     */
    private function revokeUngrantableInvitations(User $target, array $newRoles): void
    {
        WorkspaceInvitation::query()
            ->where('workspace_id', $this->context->workspaceId())
            ->where('invited_by_user_id', $target->getKey())
            ->whereNull('accepted_at')
            ->get()
            ->each(function (WorkspaceInvitation $invitation) use ($newRoles): void {
                $granted = $this->abilitiesOf($invitation->roles());

                $missing = array_values(array_filter(
                    $granted,
                    fn (string $ability) => ! AbilityMap::allows($newRoles, $ability)
                ));

                if ($missing === []) {
                    return;
                }

                $this->audit->log('member.invitation.revoked', $invitation, before: [
                    'email' => (string) $invitation->email,
                    'roles' => $invitation->roles(),
                    'reason' => 'inviter_demoted',
                ]);

                $invitation->delete();
            });
    }

    /**
     * A CHILD WORKSPACE-EK TULAJDONOSI KÖRE követi a parentét.
     *
     * A `CreateSubAccount` a létrehozáskori állapotot MÁSOLJA — az pillanatkép. Mérve, mit
     * jelentett önmagában: parent owner O létrehoz egy childot, később O-t leváltja P; a
     * child tagsága változatlanul `O=owner` marad, P-nek pedig NULLA tagsága van benne. A
     * child ott van a parent al-fiók-listáján, a parent számlájára fogy, beleszámít a
     * plafonba — de a JELENLEGI tulajdonos nem tud belépni, tagot kezelni vagy törölni.
     * Élesebben: a távozó kolléga megtartja az owner-jogot minden általa létrehozott
     * al-fiókon.
     *
     * A tükrözés SZŰK: csak az `owner` szerepet adja és veszi. A childban külön kiosztott
     * szerepek érintetlenek maradnak — a sub-account tagsága a childé, nem a parenté.
     */
    /** A tag tulajdonosi jogának elvétele MINDEN al-fiókról (a `RemoveMember` hívja). */
    public function dropChildOwnership(User $target): void
    {
        $this->syncChildOwnership($target, isOwner: false);
    }

    private function syncChildOwnership(User $target, bool $isOwner): void
    {
        $children = $this->context->workspace()->children()->get();

        foreach ($children as $child) {
            $member = WorkspaceMember::query()
                ->where('workspace_id', $child->getKey())
                ->where('user_id', $target->getKey())
                ->first();

            if ($isOwner) {
                if ($member === null) {
                    WorkspaceMember::create([
                        'workspace_id' => $child->getKey(),
                        'user_id' => $target->getKey(),
                        'roles' => [Role::Owner->value],
                    ]);

                    continue;
                }

                if (! in_array(Role::Owner->value, $member->roles(), true)) {
                    $member->update(['roles' => [...$member->roles(), Role::Owner->value]]);
                }

                continue;
            }

            if ($member === null || ! in_array(Role::Owner->value, $member->roles(), true)) {
                continue;
            }

            $remaining = array_values(array_diff($member->roles(), [Role::Owner->value]));

            // A CSAK-TÜKRÖZÖTT tagság megszűnik; a childban külön kapott szerepek maradnak.
            $remaining === [] ? $member->delete() : $member->update(['roles' => $remaining]);
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
