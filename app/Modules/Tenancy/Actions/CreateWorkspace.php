<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ÚJ WORKSPACE a workspace-váltóból (v1.1 · Shell).
 *
 * A létrehozó `owner` lesz. A művelet más tenant adatához semmit nem kapcsol: az új
 * workspace üres, `free` csomagon indul (a számlázás/entitlement a ventPay dolga).
 *
 * PLAFON: a felhasználó által TULAJDONOLT workspace-ek száma korlátos — egy elszabadult
 * kliens vagy visszaélés korlátlan tenantot hozna létre, mindegyik saját kvótával. A
 * plafont a felhasználó során tartott zárral védjük, hogy két párhuzamos kérés ne
 * léphesse át.
 */
final class CreateWorkspace
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(User $actor, string $name): Workspace
    {
        $max = (int) config('tenancy.workspaces.max_owned', 20);

        return DB::transaction(function () use ($actor, $name, $max): Workspace {
            User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            $owned = WorkspaceMember::query()
                ->where('user_id', $actor->getKey())
                ->whereJsonContains('roles', Role::Owner->value)
                ->count();

            if ($owned >= $max) {
                throw ValidationException::withMessages([
                    'name' => __('ventmail.workspaces.limit', ['max' => $max]),
                ]);
            }

            $workspace = Workspace::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
                'plan' => 'free',
            ]);

            WorkspaceMember::create([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $actor->getKey(),
                'roles' => [Role::Owner->value],
            ]);

            $this->audit->log('workspace.created', $workspace, after: [
                'name' => $name,
                'workspace_uuid' => (string) $workspace->uuid,
            ], workspaceId: (int) $workspace->getKey(), actor: $actor);

            return $workspace;
        });
    }
}
