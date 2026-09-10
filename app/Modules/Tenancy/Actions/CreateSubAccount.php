<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SUB-ACCOUNT (ügynökségi al-workspace) létrehozása — spec 3.2.
 *
 * ## Miért külön workspace, és nem „mappa"
 *
 * Mert a sub-account IZOLÁCIÓJA ugyanolyan szigorú, mint a tenant-izolációé (golden rule 6):
 * saját domainek, saját suppression-lista, saját napló, saját kvóta. Egy „mappa" ezt nem
 * tudná — a global scope egyetlen `workspace_id`-re szűr, és pont ez a védelem lényege.
 *
 * ## KI LESZ TAG a childban — és miért nem a létrehozó automatikusan `owner`
 *
 * Tagot kell adni, különben az ügynökség létrehozna egy sub-accountot, amit utána SENKI nem
 * lát: a tagság a ventMail saját táblájából dől el, nem a parent-kapcsolatból.
 *
 * A létrehozó viszont NEM kap automatikusan `owner`-t. A `subAccount.manage` szándékosan az
 * `admin`-é is, az `owner` viszont `billing.manage`-dzsel jár — és a child fogyása a PARENT
 * konszolidált számlájára megy. Az „admin nem költhet" szabály így egy al-fiókon keresztül
 * megkerülhető lett volna: az admin a saját maga tulajdonolta childban bármit beállíthatna,
 * a számlát pedig a parent tulajdonosa kapja.
 *
 * A szabály ezért: a PARENT TULAJDONOSAI lesznek a child tulajdonosai, a létrehozó pedig a
 * SAJÁT parent-beli szerepeit viszi át. Ha a parentnek nem lenne tulajdonosa (az
 * utolsó-tulajdonos invariáns miatt nem fordulhat elő), a létrehozó kapja — egy elérhetetlen
 * al-fiók rosszabb, mint egy pontatlan szerep.
 */
final class CreateSubAccount
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(string $name): Workspace
    {
        $actor = Auth::user();

        if ($actor === null || ! Gate::forUser($actor)->allows('subAccount.manage')) {
            throw new AuthorizationException(__('ventmail.members.subAccountNotAllowed'));
        }

        $parent = $this->context->workspace();

        // DARABSZÁM-PLAFON: az al-fiók INGYEN létrehozható, a fogyása viszont a parent
        // számlájára megy — plafon nélkül egy ciklus ezer workspace-t hozna létre, mind
        // saját kvótával, ugyanarra a számlára.
        $max = (int) config('tenancy.sub_accounts.max', 50);

        if ($this->context->workspace()->children()->count() >= $max) {
            throw new RuntimeException(__('ventmail.members.subAccountLimit', ['max' => $max]));
        }

        // AZ EGYSZINTŰSÉGET az adatbázis-trigger őrzi (`workspace_parents_single_level`),
        // de a hibát ITT is meg akarjuk fogni, olvasható üzenettel: aki már child, az nem
        // hozhat létre további sub-accountot.
        if ($parent->parents()->exists()) {
            throw new AuthorizationException(__('ventmail.members.subAccountSingleLevel'));
        }

        return DB::transaction(function () use ($parent, $name, $actor): Workspace {
            $child = Workspace::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
                'plan' => $parent->plan,
            ]);

            $parent->children()->attach($child->getKey());

            $this->seedMembership($parent, $child, $actor);

            $this->audit->log('subAccount.created', $child, after: [
                'name' => $name,
                'child_workspace_uuid' => (string) $child->uuid,
            ]);

            return $child;
        });
    }

    /**
     * A CHILD KEZDŐ TAGSÁGA: a parent tulajdonosai + a létrehozó a saját szerepeivel.
     */
    private function seedMembership(Workspace $parent, Workspace $child, User $actor): void
    {
        $parentMembers = WorkspaceMember::query()
            ->where('workspace_id', $parent->getKey())
            ->get();

        $owners = $parentMembers->filter(
            fn (WorkspaceMember $member) => in_array(Role::Owner->value, $member->roles(), true)
        );

        foreach ($owners as $owner) {
            WorkspaceMember::create([
                'workspace_id' => $child->getKey(),
                'user_id' => $owner->user_id,
                'roles' => [Role::Owner->value],
            ]);
        }

        if ($owners->contains(fn (WorkspaceMember $member) => (int) $member->user_id === (int) $actor->getKey())) {
            return;
        }

        $actorRoles = $parentMembers
            ->first(fn (WorkspaceMember $member) => (int) $member->user_id === (int) $actor->getKey())
            ?->roles() ?? [];

        WorkspaceMember::create([
            'workspace_id' => $child->getKey(),
            'user_id' => $actor->getKey(),
            // Ha a parentnek NINCS tulajdonosa (elvileg lehetetlen), a létrehozó kapja —
            // egy elérhetetlen al-fiók rosszabb, mint egy pontatlan szerep.
            'roles' => $owners->isEmpty() ? [Role::Owner->value] : ($actorRoles ?: [Role::Admin->value]),
        ]);
    }
}
