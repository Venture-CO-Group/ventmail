<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Console;

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Actions\VerifyInboundMx;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * `php artisan inbound:verify-mx`
 *
 * A bejövő fogadás bizalmi horgonyának ÚJRAELLENŐRZÉSE. Ütemezetten fut: az MX-rekord
 * bármikor eltűnhet (domain-átvitel, DNS-hiba, az ügyfél átállt máshova), és onnantól nem a
 * mi dolgunk levelet fogadni rá.
 */
final class VerifyInboundMxCommand extends Command
{
    protected $signature = 'inbound:verify-mx {--domain= : csak ez a domain}';

    protected $description = 'A bejövő MX-rekordok ellenőrzése minden tenantnál.';

    public function handle(WorkspaceContext $context, VerifyInboundMx $verify): int
    {
        $checked = ['verified' => 0, 'pending' => 0, 'failed' => 0];

        try {
            Workspace::query()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $verify, &$checked): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    Domain::query()
                        ->when($this->option('domain'), fn ($query) => $query->where('name', $this->option('domain')))
                        // A `disabled` az ügyfél döntése: aki nem kér bejövő fogadást, azt
                        // nem kezdjük el magunktól ellenőrizni.
                        ->where('inbound_mx_status', '!=', 'disabled')
                        ->each(function (Domain $domain) use ($verify, &$checked): void {
                            $checked[$verify($domain)]++;
                        });
                }
            });
        } finally {
            $context->forget();
        }

        $this->info(sprintf(
            'Ellenőrizve — verified: %d, pending: %d, failed: %d',
            $checked['verified'],
            $checked['pending'],
            $checked['failed'],
        ));

        return self::SUCCESS;
    }
}
