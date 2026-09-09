<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Models\LinkClick;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A KLIKK-TÉRKÉP címzett-dimenziójának összevonása a retenció után (golden rule 10).
 *
 * ## „a nyers esemény törlődik, az aggregátum marad" — pontosan ez
 *
 * A klikk-tábla nyers grainje a (link, CÍMZETT) páros: ez az, ami a retenció után nem
 * maradhat. A per-link aggregátum viszont a kampány-riport része, és annak túl kell élnie —
 * ha törölnénk, a fél évvel korábbi kampány klikk-térképe kiürülne, és a tenant azt látná,
 * hogy a számai maguktól eltűnnek.
 *
 * Ezért az összevonás: a `clicks` és az egyedi kattintók száma MEGMARAD, a KI eltűnik.
 *
 * A B2 és a B3 ugyanezt a lépést a `campaign_recipients`-szel és a `journey_runs`-szal
 * KIHAGYTA — mindkettő review-blokkoló lett: új tábla, címzett-dimenzió, semmi lejárat. Ez
 * a parancs azért van itt a packet ELSŐ körében, mert a hibaosztály ismert.
 */
final class PurgeClickMapCommand extends Command
{
    protected $signature = 'analytics:purge-click-map';

    protected $description = 'A lejárt retenciójú klikkek címzett-dimenziójának összevonása (az aggregátum megmarad).';

    public function handle(WorkspaceContext $context, ClickMap $map): int
    {
        $days = (int) config('sending.delivery_attempt_retention_days', 90);
        $cutoff = now()->subDays($days);
        $collapsed = 0;
        $aggregated = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $map, $cutoff, &$collapsed, &$aggregated): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    // A SZÁMOLÁS A KONTEXTUSON BELÜL. Korábban a `finally` UTÁN futott,
                    // ahol a `forget()` már törölte a kontextust, tehát a `WorkspaceScope`
                    // `1 = 0`-ra vitte: a jelentés NULLÁT mondott, miközben két összevont
                    // sor volt. Egy retenciós jelentés, ami nullát mond, rosszabb, mintha
                    // nem mondana semmit.
                    $aggregated += LinkClick::query()
                        ->where('recipient_hash', ClickMap::AGGREGATED_HASH)
                        ->count();

                    $collapsed += $map->collapse(fn ($query) => $query
                        ->where('last_clicked_at', '<=', $cutoff)
                        // A MÁR ÖSSZEVONT sorokat nem vonjuk össze újra: nincs mit
                        // elvenni belőlük, és az `upsert` önmagával duplázná a számokat.
                        ->where('recipient_hash', '!=', ClickMap::AGGREGATED_HASH));
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info(sprintf(
            'Összevont klikk-sor: %d (retenció: %d nap). Aggregált sorok: %d',
            $collapsed,
            $days,
            $aggregated,
        ));

        return self::SUCCESS;
    }
}
