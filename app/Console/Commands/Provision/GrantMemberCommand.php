<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Console\Command;

/**
 * Tagság CLI-ből — csak MÁR BELÉPETT felhasználóhoz (a felhasználó sorát a ventSSO-belépés
 * hozza létre; identitást a ventMail nem gyárt). A kezdő tulajdonos és az E2E teszt-user
 * hozzárendeléséhez, amíg a felületen még nincs, aki meghívjon.
 */
final class GrantMemberCommand extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'ventmail:member:grant {workspace} {email} {role}';

    protected $description = 'Már belépett felhasználó hozzárendelése egy workspace-hez, szereppel.';

    public function handle(): int
    {
        $workspace = $this->enterWorkspace((string) $this->argument('workspace'));
        $role = Role::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $this->error('Ismeretlen szerep; engedélyezett: '.implode(', ', Role::values()));

            return self::INVALID;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [strtolower((string) $this->argument('email'))])->first();

        if ($user === null) {
            $this->error('Nincs ilyen felhasználó — előbb lépjen be egyszer a ventSSO-n keresztül.');

            return self::FAILURE;
        }

        $member = WorkspaceMember::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['roles' => [$role->value]]
        );

        // A WorkspaceMember nem Auditable (az AssignWorkspaceRoles ír explicit auditot) —
        // a CLI-út ugyanígy explicit.
        app(AuditLogger::class)->log('workspace.member_granted', $member, after: [
            'user_id' => $user->id, 'roles' => $member->roles(), 'via' => 'cli',
        ]);

        $this->info(sprintf('tag: %s → %s (%s)', $user->email, $workspace->slug, implode(',', $member->roles())));

        return self::SUCCESS;
    }
}
