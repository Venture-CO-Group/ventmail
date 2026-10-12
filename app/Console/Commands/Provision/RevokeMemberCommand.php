<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Models\User;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Console\Command;

/** Tagság visszavonása CLI-ből (az E2E teszt-user takarításához). */
final class RevokeMemberCommand extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'ventmail:member:revoke {workspace} {email}';

    protected $description = 'Felhasználó tagságának visszavonása egy workspace-ben.';

    public function handle(): int
    {
        $workspace = $this->enterWorkspace((string) $this->argument('workspace'));
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower((string) $this->argument('email'))])->first();

        $deleted = $user === null ? 0 : WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)->where('user_id', $user->id)->delete();

        $this->info("visszavonva: {$deleted} tagság");

        return $deleted > 0 ? self::SUCCESS : self::FAILURE;
    }
}
