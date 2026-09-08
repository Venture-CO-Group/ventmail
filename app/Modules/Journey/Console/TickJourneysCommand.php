<?php

declare(strict_types=1);

namespace App\Modules\Journey\Console;

use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * A JOURNEY-FUTÁSOK LÉPTETÉSE (68. kritérium).
 *
 * Percenként fut. A motor nem „fut", hanem LÉPTET: minden tick megkérdezi az esedékes
 * futásokat, és amelyik esedékes, azt egy lépéssel (vagy néhánnyal) előreviszi.
 *
 * ## A HIBA EGY FUTÁSNÁL nem állítja meg a többit
 *
 * Egy hibás journey (törölt sablon, körkörös lánc) kivételt dobhat. Ha ez megszakítaná a
 * ciklust, EGYETLEN rossz journey miatt az összes többi megállna — és a felhasználók csak
 * napokkal később vennék észre, hogy az automatizálásuk nem futott.
 */
final class TickJourneysCommand extends Command
{
    protected $signature = 'journeys:tick {--limit=500 : legfeljebb ennyi futás egy tickben}';

    protected $description = 'Az esedékes journey-futások léptetése.';

    public function handle(WorkspaceContext $context, JourneyEngine $engine): int
    {
        $ticked = 0;
        $steps = 0;
        $failed = 0;
        $limit = max(1, (int) $this->option('limit'));

        /*
         | A HÍVÓ KONTEXTUSÁT VISSZAÁLLÍTJUK, nem eldobjuk.
         |
         | A `forget()` egy tiszta CLI-futásnál helyes — de ezt a parancsot egy szolgáltatás
         | is meghívhatja (`Artisan::call`), és akkor a hívó tenant-kontextusa elveszik: az
         | utána következő lekérdezések a `WorkspaceScope` fail-closed ágára futnak (`1 = 0`),
         | és a hívó ÜRES eredményt lát valódi adat helyett.
         |
         | Ez mérve megtörtént a `ContactGdpr`-ban (a `logs:redact-subject` hívása után a
         | művelet HAMISAN jelentette, hogy nincs mit megőrizni), és a saját tesztem is
         | ebbe futott bele. A `finally` így a KORÁBBI kontextust adja vissza.
         */
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $engine, $limit, &$ticked, &$steps, &$failed): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $due = JourneyRun::query()
                        ->due()
                        // A RÉGEBBI futás előbb: egy frissen belépett kontakt ne előzze meg
                        // azt, aki már napok óta vár egy lépésre.
                        ->orderBy('resume_at')
                        ->orderBy('id')
                        ->limit($limit)
                        ->get();

                    foreach ($due as $run) {
                        try {
                            $steps += $engine->tick($run);
                            $ticked++;
                        } catch (Throwable $e) {
                            report($e);
                            $failed++;
                        }
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Futás: {$ticked}, lépés: {$steps}, hibás: {$failed}");

        return self::SUCCESS;
    }
}
