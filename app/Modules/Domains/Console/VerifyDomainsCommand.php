<?php

declare(strict_types=1);

namespace App\Modules\Domains\Console;

use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A domain-hitelesítés ütemezett újraellenőrzése.
 *
 * A `pending` domainek sűrűbben (az ügyfél épp most veszi fel a rekordokat), a `verified`-ek
 * naponta — utóbbi a `verified → failed` visszaesés miatt kötelező: egy törölt DKIM-rekord
 * nem maradhat észrevétlen (golden rule 8).
 */
final class VerifyDomainsCommand extends Command
{
    protected $signature = 'domains:verify {--pending : csak a még nem hitelesítettek} {--verified : csak a hitelesítettek}';

    protected $description = 'A küldő domainek DNS-hitelesítésének (újra)ellenőrzése.';

    public function handle(VerifyDomainDns $verify, WorkspaceContext $context): int
    {
        $checked = 0;

        // Tenantonként megyünk: a global scope-nak kontextus kell, és így a hibás
        // workspace nem állítja meg a többiét.
        Workspace::query()->each(function (Workspace $workspace) use ($verify, $context, &$checked): void {
            $context->set($workspace);

            Domain::query()
                ->when($this->option('pending'), fn ($query) => $query->whereIn('status', ['pending', 'failed']))
                ->when($this->option('verified'), fn ($query) => $query->where('status', 'verified'))
                ->each(function (Domain $domain) use ($verify, &$checked): void {
                    $verify->handle($domain);
                    $checked++;
                });
        });

        $context->forget();

        $this->info("Ellenőrzött domainek: {$checked}");

        return self::SUCCESS;
    }
}
