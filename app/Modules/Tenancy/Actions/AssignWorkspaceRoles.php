<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Policies\AbilityMap;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

        /*
         | MINDEN ÍRÁS EGY TRANZAKCIÓBAN — ÉS AZ AUDIT IS BENNE.
         |
         | A művelet négy táblát érint: a szülő tagságát, a meghívókat, az al-fiókok
         | tagságát és az audit-naplót. Tranzakció nélkül a security-review hibainjektálása
         | ezt mérte: ha a child-oldali írás elbukik (a valós megfelelője a
         | `(workspace_id, user_id)` unique ütközése két párhuzamos kiosztásnál), a SZÜLŐ
         | szerep-változás már kint van, az audit-sor viszont soha nem születik meg — a
         | rendszer legprivilegizáltabb művelete nyom nélkül megy végbe (golden rule 11).
         |
         | A másik, élesebb következmény a RÉSZLEGES TÜKRÖZÉS: ha a visszavonás a 25.
         | al-fióknál bukik, az 1–24-ről a jog elesett, a 26–50-ről nem — és ezt semmi nem
         | javítja utólag, mert a backfill-parancs kizárólag hozzáad. Megszakadt offboarding
         | után tartós, észrevétlen hozzáférés-maradvány.
         |
         | Az AUDIT a szülő-írás UTÁN, de a tranzakción BELÜL: ha bármi elbukik, a napló és
         | az adat együtt gördül vissza — nincs olyan állapot, ahol az egyik látszik és a
         | másik nem.
         */
        return DB::transaction(function () use ($target, $roleValues, $existing): WorkspaceMember {
            $member = WorkspaceMember::updateOrCreate(
                ['workspace_id' => $this->context->workspaceId(), 'user_id' => $target->getKey()],
                ['roles' => $roleValues],
            );

            // A KIFEJEZETT KIOSZTÁS EMBERI DÖNTÉS: a tükrözés-jelölés törlődik.
            //
            // Enélkül a jelölés „ragadós" volt: a child SAJÁT tulajdonosának kiosztása egy
            // korábban tükrözött soron továbbra is tükrözöttnek számított, és a parent
            // leszedte — pedig a childban valaki kifejezetten úgy döntött, hogy odaadja.
            $member->forceFill(['owner_mirrored' => false])->save();

            // Golden rule 11: a szerep-kiosztás a legprivilegizáltabb admin-művelet —
            // enélkül valaki jogot oszthatna magának úgy, hogy nyoma sem marad. A sor
            // KÖZVETLENÜL a döntés után íródik, nem a mellékhatások végén.
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

            // A LEFOKOZOTT TAG DÖNTÉSE SEM ÉLHET TOVÁBB.
            //
            // A `RemoveMember` revokálja az eltávolított tag nyitott meghívóit, a lefokozás
            // viszont nem tette — pedig az az olcsóbb, gyakoribb reakció. Mérve: `admin` A
            // `admin`-meghívót ír, az owner `analyst`-ra fokozza A-t, a meghívó nyitva
            // marad `["admin"]` szereppel, a meghívott elfogadja — és `admin` tagságot kap
            // attól, akitől ezt a jogot épp elvették.
            //
            // CSAK a már nem kiosztható meghívók esnek el: egy előléptetés (több szerep)
            // nem vonja vissza a korábbi, továbbra is fedezett meghívókat.
            $this->revokeUngrantableInvitations($target, $roleValues);

            // A CHILD WORKSPACE-EK tulajdonosi köre követi a parentét (lásd a metódus
            // indoklását): enélkül a távozó kolléga megtartaná az owner-jogot minden általa
            // létrehozott al-fiókon, az új tulajdonos pedig be sem lépne oda.
            //
            // CSAK AKKOR, HA A TULAJDONOSI BIT VÁLTOZOTT: egy szerep-módosítás, ami az
            // ownert nem érinti, ne írjon 50 al-fiók naplójába — az audit append-only,
            // DB-oldali 365 napos alsó időhatárral, tehát a fölösleges sor egy évig velünk
            // marad.
            $wasOwner = in_array(Role::Owner->value, $existing?->roles() ?? [], true);
            $isOwner = in_array(Role::Owner->value, $roleValues, true);

            if ($wasOwner !== $isOwner) {
                $this->syncChildOwnership($target, $isOwner);
            }

            return $member;
        });
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
     * A CHILD WORKSPACE-EK TULAJDONOSI KÖRE követi a parentét — SZIGORÚ HATÁROKKAL.
     *
     * ## Miért kell egyáltalán
     *
     * A `CreateSubAccount` a létrehozáskori állapotot másolja, az pedig pillanatkép. Mérve:
     * a childot létrehozó owner távozása után a child tagsága változatlan maradt, az ÚJ
     * tulajdonosnak NULLA tagsága lett benne — a child ott maradt az al-fiók-listán, a
     * parent számlájára fogyott, de a jelenlegi tulajdonos be sem tudott lépni.
     *
     * ## Miért csak a TÜKRÖZÖTT tagságot veszi vissza
     *
     * Mert a mellékhatás a CHILDBAN csapódik le, ahol a célpont ERŐSEBB lehet az aktornál —
     * az `assertTargetNotStronger()` viszont a PARENTBEN mér. A security-review ezt mérte
     * meg: `admin` A a parentben eltávolítja `U`-t (aki ott csak `editor`, tehát a törlés
     * jogos), `U` viszont a CHILDBAN `owner` — és a korábbi, válogatás nélküli
     * `array_diff(roles, ['owner'])` a child tulajdonosát is elvitte. Ugyanaz a jogosultsági
     * inverzió, csak áthelyezve.
     *
     * Ezért: a visszavonás KIZÁRÓLAG a `source = parent_owner_sync` sorokra fut — azokra,
     * amiket maga a tükrözés hozott létre. A childban KÜLÖN kiosztott `owner` emberi
     * döntés, ahhoz a parent felől nem nyúlunk.
     *
     * ## És a child sem maradhat tulajdonos nélkül
     *
     * Ugyanaz az invariáns, mint a parentben — csak a childra kiértékelve.
     *
     * ## Minden child-oldali változás AUDITÁLT, a CHILD naplójába
     *
     * Golden rule 11. A tükrözés jogot oszt és von vissza; ha nyom nélkül tenné, a child
     * naplójában egy tulajdonos „csak úgy" megjelenne vagy eltűnne — a saját mércénk szerint
     * ez blokkoló.
     */
    /** A tag TÜKRÖZÖTT tulajdonosi jogának elvétele az al-fiókokról (a `RemoveMember` hívja). */
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

            $isOwner
                ? $this->mirrorOwnership($child, $target, $member)
                : $this->withdrawMirroredOwnership($child, $target, $member);
        }
    }

    private function mirrorOwnership(Workspace $child, User $target, ?WorkspaceMember $member): void
    {
        if ($member === null) {
            $created = WorkspaceMember::create([
                'workspace_id' => $child->getKey(),
                'user_id' => $target->getKey(),
                'roles' => [Role::Owner->value],
            ]);

            $created->forceFill(['owner_mirrored' => true])->save();

            $this->auditChild($child, 'member.roles.assigned', $created, [], [Role::Owner->value], $target);

            return;
        }

        if (in_array(Role::Owner->value, $member->roles(), true)) {
            return;
        }

        $before = $member->roles();

        /*
         | A MEGLÉVŐ SORON IS JELÖLNI KELL.
         |
         | Ez volt a fail-open ág: aki a childban emberi döntésből `editor` volt, majd a
         | parentben `owner` lett, JELÖLETLEN tulajdonosi jogot kapott — és a parentből való
         | eltávolítása után is `owner` maradt a childban. Offboarding után teljes
         | hozzáférés egy olyan workspace-hez, ami a parent számlájára fogy.
         */
        $member->forceFill([
            'roles' => [...$before, Role::Owner->value],
            'owner_mirrored' => true,
        ])->save();

        $this->auditChild($child, 'member.roles.assigned', $member, $before, $member->roles(), $target);
    }

    private function withdrawMirroredOwnership(Workspace $child, User $target, ?WorkspaceMember $member): void
    {
        // A childban KÜLÖN kiosztott TULAJDONOSI JOGHOZ a parent felől nem nyúlunk.
        if ($member === null || ! $member->hasMirroredOwnership()) {
            return;
        }

        if (! in_array(Role::Owner->value, $member->roles(), true)) {
            return;
        }

        // A CHILD sem maradhat tulajdonos nélkül — ugyanaz az invariáns, a childra mérve.
        $otherOwners = WorkspaceMember::query()
            ->where('workspace_id', $child->getKey())
            ->whereKeyNot($member->getKey())
            ->get()
            ->contains(fn (WorkspaceMember $other) => in_array(Role::Owner->value, $other->roles(), true));

        if (! $otherOwners) {
            /*
             | A CHILD UTOLSÓ TULAJDONOSA MARAD — de a művelet nem sikerül csendben.
             |
             | A hívó (`RemoveMember`) sikert jelez, miközben az illető megtartja a
             | child-tulajdonjogot: ezt az üzemeltetőnek látnia kell, különben azt hiszi,
             | hogy a hozzáférés mindenhol megszűnt.
             */
            Log::warning('Az al-fiók utolsó tulajdonosa megmarad a parent-oldali döntés ellenére.', [
                'child_workspace_id' => $child->getKey(),
                'user_id' => $target->getKey(),
            ]);

            return;
        }

        $before = $member->roles();
        $remaining = array_values(array_diff($before, [Role::Owner->value]));

        $member->forceFill(['owner_mirrored' => false])->save();

        if ($remaining === []) {
            $this->auditChild($child, 'member.removed', $member, $before, [], $target);

            $member->delete();

            return;
        }

        $member->update(['roles' => $remaining]);

        $this->auditChild($child, 'member.roles.assigned', $member, $before, $remaining, $target);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function auditChild(Workspace $child, string $action, WorkspaceMember $member, array $before, array $after, User $target): void
    {
        $this->audit->log(
            $action,
            $member,
            before: ['roles' => $before],
            after: ['roles' => $after],
            context: [
                'target_user_id' => $target->getKey(),
                // A CHILD naplójából ki kell derülnie, hogy a döntés NEM ott született:
                // enélkül az al-fiók üzemeltetője egy megmagyarázhatatlan sort látna.
                'reason' => 'parent_owner_sync',
                'parent_workspace_id' => $this->context->workspaceId(),
            ],
            // A CHILD naplójába, EXPLICIT workspace-szel: a kontextus a parenté, és a
            // tenant a saját naplójában keresi, hogyan változott a tulajdonosi köre.
            workspaceId: (int) $child->getKey(),
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
