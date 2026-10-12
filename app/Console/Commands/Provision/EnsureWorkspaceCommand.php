<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Console\Command;

/** A (rendszer-)workspace létrehozása — idempotens: létezőnél csak a nevét igazítja. */
final class EnsureWorkspaceCommand extends Command
{
    protected $signature = 'ventmail:workspace:ensure {slug} {--name=}';

    protected $description = 'Workspace létrehozása slug alapján (idempotens) — éles provisioning.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1) {
            $this->error('Érvénytelen slug.');

            return self::INVALID;
        }

        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => (string) ($this->option('name') ?: $slug)]
        );

        $this->info(sprintf('workspace %s (%s) %s', $workspace->slug, $workspace->uuid, $workspace->wasRecentlyCreated ? 'létrehozva' : 'már létezett'));

        return self::SUCCESS;
    }
}
