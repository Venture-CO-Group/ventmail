<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

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

/**
 * SUB-ACCOUNT (ügynökségi al-workspace) létrehozása — spec 3.2.
 *
 * ## Miért külön workspace, és nem „mappa"
 *
 * Mert a sub-account IZOLÁCIÓJA ugyanolyan szigorú, mint a tenant-izolációé (golden rule 6):
 * saját domainek, saját suppression-lista, saját napló, saját kvóta. Egy „mappa" ezt nem
 * tudná — a global scope egyetlen `workspace_id`-re szűr, és pont ez a védelem lényege.
 *
 * ## A LÉTREHOZÓ tag lesz a childban
 *
 * Enélkül az ügynökség létrehozna egy sub-accountot, amit utána SENKI nem lát: a tagság a
 * ventMail saját táblájából dől el, nem a parent-kapcsolatból. A parent-kapcsolat a
 * SZÁMLÁZÁSÉ és az összesítő nézeteké, nem a belépésé.
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
            throw new AuthorizationException('Nincs jogosultságod sub-accountot kezelni.');
        }

        $parent = $this->context->workspace();

        // AZ EGYSZINTŰSÉGET az adatbázis-trigger őrzi (`workspace_parents_single_level`),
        // de a hibát ITT is meg akarjuk fogni, olvasható üzenettel: aki már child, az nem
        // hozhat létre további sub-accountot.
        if ($parent->parents()->exists()) {
            throw new AuthorizationException('A sub-account hierarchia egyszintű: egy al-fiók alá nem hozható létre újabb.');
        }

        return DB::transaction(function () use ($parent, $name, $actor): Workspace {
            $child = Workspace::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
                'plan' => $parent->plan,
            ]);

            $parent->children()->attach($child->getKey());

            WorkspaceMember::create([
                'workspace_id' => $child->getKey(),
                'user_id' => $actor->getKey(),
                'roles' => [Role::Owner->value],
            ]);

            $this->audit->log('subAccount.created', $child, after: [
                'name' => $name,
                'child_workspace_uuid' => (string) $child->uuid,
            ]);

            return $child;
        });
    }
}
