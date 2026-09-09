<?php

declare(strict_types=1);

namespace App\Modules\Journey\Console;

use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A LEZÁRT JOURNEY-FUTÁSOK címének és kontextusának redakciója (golden rule 10).
 *
 * ## Miért kell
 *
 * A `journey_runs.email` nyers címet, a `context` pedig a trigger nyers payloadját tárolja,
 * és a B3 egyiket sem érte lejárattal. Mérve: `ContactGdpr::erase()` után a
 * `contacts.email` már `torolt-…@erased.invalid`, a `journey_runs.email` viszont
 * `anna@pelda.hu`, a `context` pedig `{"telefon":"+36301234567"}` — érintetlenül.
 *
 * Ez betűre ugyanaz a hiba, amit a B2 a `campaign_recipients`-szel elkövetett, és amire
 * ugyanez az ág megírta a `campaigns:purge-recipients`-t. Egy új tábla nyers címmel és
 * retenció nélkül: a golden rule 10 pontosan ezt az osztályt írja le.
 *
 * ## Miért redakció, és miért nem törlés
 *
 * A futás a journey-riport alapja („belépett 1000, befejezte 870, kilépett 130"). Ha egy
 * retenciós takarítás sorokat törölne, a fél évvel korábbi journey riportja visszamenőleg
 * megváltozna. Az `email_hash` MEGMARAD: a riporthoz elég (ugyanaz a cím-e), az
 * azonosításhoz nem.
 *
 * ## Miért csak LEZÁRT futás
 *
 * Egy `active`/`waiting` futásból a cím kivétele azt jelentené, hogy a motor nem tudja,
 * kinek küldjön — a futó futás címe MUNKA-adat, nem archívum.
 */
final class PurgeJourneyRunsCommand extends Command
{
    protected $signature = 'journeys:purge-runs';

    protected $description = 'A lejárt retenciójú, LEZÁRT journey-futásokban a nyers cím és a kontextus redakciója.';

    /** A redaktált cím helyőrzője — ugyanaz, amit a `logs:redact-subject` ír. */
    private const PLACEHOLDER = '(redaktált)';

    public function handle(WorkspaceContext $context): int
    {
        $days = (int) config('sending.delivery_attempt_retention_days', 90);
        $cutoff = now()->subDays($days);
        $redacted = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $cutoff, &$redacted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $redacted += JourneyRun::query()
                        ->whereIn('status', ['completed', 'exited', 'failed'])
                        ->whereNotNull('finished_at')
                        ->where('finished_at', '<=', $cutoff)
                        ->where('email', '!=', self::PLACEHOLDER)
                        ->update([
                            'email' => self::PLACEHOLDER,
                            // A KONTEXTUS IS: a trigger payloadja az űrlap-válaszokat is
                            // hordozza (telefonszám, cím), amiről a futás lezárása után
                            // semmilyen funkció nem dolgozik.
                            'context' => '{}',
                            'updated_at' => now(),
                        ]);
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info(sprintf('Redaktált journey-futás: %d (retenció: %d nap)', $redacted, $days));

        return self::SUCCESS;
    }
}
