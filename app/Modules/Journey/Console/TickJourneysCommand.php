<?php

declare(strict_types=1);

namespace App\Modules\Journey\Console;

use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
    protected $signature = 'journeys:tick'
        .' {--limit=500 : legfeljebb ennyi futás egy tickben, workspace-enként}'
        .' {--max-seconds=50 : az egész futás időbüdzséje másodpercben}';

    /** A forgó workspace-sorrend kurzora (review REV-S3). */
    public const CURSOR_KEY = 'journeys:tick:cursor';

    protected $description = 'Az esedékes journey-futások léptetése.';

    public function handle(WorkspaceContext $context, JourneyEngine $engine): int
    {
        $ticked = 0;
        $steps = 0;
        $failed = 0;
        $limit = max(1, (int) $this->option('limit'));
        $budget = max(5, (int) $this->option('max-seconds'));
        $deadline = microtime(true) + $budget;

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

        /*
         | A WORKSPACE-SORREND FORGÓ (review REV-S3): a büdzsé lejártakor a következő tick a
         | megszakított workspace UTÁNI tenanttal kezd, nem újra a legkisebb id-vel — különben
         | a nagy id-jű tenantok egy terhelt időszakban soha nem jutnának sorra.
         */
        $cursor = (int) Cache::get(self::CURSOR_KEY, 0);
        $interruptedAt = null;

        try {
            Workspace::query()->orderByRaw('case when id > ? then 0 else 1 end, id', [$cursor])->chunk(50, function ($workspaces) use ($context, $engine, $limit, $deadline, &$ticked, &$steps, &$failed, &$interruptedAt): bool {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $due = JourneyRun::query()
                        ->due()
                        // A SZÜNETELTETETT journey futásai nem lépnek (v1.1): ha a sorban
                        // maradnának, a limitet kitöltve kiéheztetnék az aktív journey-ket.
                        ->whereNotIn('journey_id', Journey::query()->where('status', 'paused')->select('id'))
                        // EAGER LOAD: a `shouldExit()` a `journey`-t, a `tick()` a
                        // `currentStep`-et olvassa. Enélkül mérve 9,1 lekérdezés/futás/tick
                        // — klasszikus N+1 egy percenként futó parancsban.
                        ->with(['journey', 'currentStep'])
                        // A RÉGEBBI futás előbb: egy frissen belépett kontakt ne előzze meg
                        // azt, aki már napok óta vár egy lépésre.
                        ->orderBy('resume_at')
                        ->orderBy('id')
                        ->limit($limit)
                        ->get();

                    foreach ($due as $run) {
                        /*
                         | IDŐBÜDZSÉ — a `withoutOverlapping` nem elég.
                         |
                         | Mérve ~3,6 ms/lépés: 1000 workspace × 500 futás egy „percenkénti"
                         | tickben ~30 perc, és a `withoutOverlapping` közben 29 futást
                         | kihagy. Egy `wait 5 perc` pontossága így összeomlik, és a
                         | workspace-sorrend (`orderBy('id')`) miatt MINDIG ugyanazok az
                         | utolsó tenantok éheznek — a nagy id-jű workspace soha nem jut sorra.
                         |
                         | A büdzsé lejárta nem hiba: a következő tick ott folytatja, ahol ez
                         | abbahagyta, mert az esedékesség az adatbázisban áll.
                         */
                        if (microtime(true) >= $deadline) {
                            $this->warn('Az időbüdzsé lejárt — a maradék futás a következő tickben jön.');
                            $interruptedAt = (int) $workspace->getKey();

                            // A `false` a `chunkById`-t is MEGÁLLÍTJA: enélkül csak ezt a
                            // workspace-et hagynánk ki, a következőt viszont elkezdenénk.
                            return false;
                        }

                        try {
                            $steps += $engine->tick($run);
                            $ticked++;
                        } catch (Throwable $e) {
                            report($e);
                            $failed++;
                        }
                    }
                }

                return true;
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        Cache::put(self::CURSOR_KEY, $interruptedAt ?? 0, now()->addDay());

        $this->info("Futás: {$ticked}, lépés: {$steps}, hibás: {$failed}");

        return self::SUCCESS;
    }
}
