<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Console;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\VariantWinner;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * AZ A/B GYŐZTES AUTOMATA KIHIRDETÉSE (64. kritérium).
 *
 * ## Miért kell ez a parancs
 *
 * A `VariantWinner` megvolt, de SENKI nem hívta: grep szerint egyedül a teszt. A 64.
 * kritérium „automata kihirdetés"-e tehát papíron létezett, a gyakorlatban nem — a tenant
 * beállított egy A/B tesztet, és a győztes soha nem lett kihirdetve.
 *
 * ## Miért VÁR a kihirdetés
 *
 * A nyitás és a klikk KÉSVE érkezik. Közvetlenül a küldés után kihirdetve mindig az a
 * variáns nyerne, akinek a címzettjei épp a gépnél ültek — a tárgysor helyett az időzóna
 * döntene. A `winner_after_minutes` ezért a kampány saját beállítása, alapból 4 óra.
 *
 * ## Miért nem hasal el egy kampány hibája a többiben
 *
 * A `declare()` SZÁNDÉKOSAN kivételt dob, ha a minta kicsi (`MIN_SAMPLE`) — kis mintán a
 * különbség mintavételi zaj, és egy hamis győztes alapján a tenant a következő tíz
 * kampányát rossz irányba optimalizálja. Ez itt NEM hiba, hanem a normális eset: a kampány
 * marad kihirdetés nélkül, és a következő futás újra megnézi.
 *
 * A KORÁBBI KONTEXTUST VISSZAADJUK, nem eldobjuk: egy szolgáltatásból hívva a `forget()` a
 * hívó lekérdezéseit a `WorkspaceScope` fail-closed ágára vinné, és üres eredményt adna.
 */
final class AnnounceVariantWinnersCommand extends Command
{
    protected $signature = 'campaigns:announce-winners';

    protected $description = 'A lezárt A/B kampányok győztesének kihirdetése a beállított kritérium szerint.';

    public function handle(WorkspaceContext $context, VariantWinner $winner): int
    {
        $declared = 0;
        $waiting = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $winner, &$declared, &$waiting): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $campaigns = Campaign::query()
                        ->whereNotNull('winner_criterion')
                        ->whereNull('winner_declared_at')
                        ->where('status', 'sent')
                        ->whereNotNull('finished_at')
                        ->orderBy('id')
                        ->get();

                    foreach ($campaigns as $campaign) {
                        $due = $campaign->finished_at
                            ?->addMinutes((int) $campaign->winner_after_minutes)
                            ->isPast() === true;

                        if (! $due) {
                            $waiting++;

                            continue;
                        }

                        try {
                            $result = $winner->declare($campaign, (string) $campaign->winner_criterion);
                        } catch (InvalidArgumentException) {
                            // KICSI MINTA vagy ismeretlen kritérium: nem hiba, a kampány
                            // marad kihirdetés nélkül. A következő futás újra megnézi.
                            continue;
                        } catch (Throwable $e) {
                            $this->warn(sprintf(
                                'A(z) %s kampány győztesét nem sikerült kihirdetni: %s',
                                (string) $campaign->uuid,
                                $e::class
                            ));

                            continue;
                        }

                        // A DÖNTETLEN is DÖNTÉS: `null` győztes esetén sem próbálkozunk
                        // örökké — a `winner_declared_at` lezárja a kérdést, különben a
                        // parancs minden negyed órában újraszámolna ugyanazt.
                        $campaign->forceFill(['winner_declared_at' => now()])->save();

                        if ($result !== null) {
                            $declared++;
                        }
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info(sprintf('Kihirdetett győztes: %d, még várakozó kampány: %d', $declared, $waiting));

        return self::SUCCESS;
    }
}
