<?php

declare(strict_types=1);

namespace App\Modules\Journey\Console;

use App\Modules\Audience\Models\Segment;
use App\Modules\Journey\Actions\FireJourneyEvent;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * SZEGMENSBE LÉPÉS mint trigger (69. kritérium).
 *
 * ## Miért ütemezett parancs, és nem esemény
 *
 * A szegmens egy LEKÉRDEZÉS, nem egy lista: nincs „belépés" pillanata. Egy kontakt attól
 * kerül bele, hogy megváltozott egy tulajdonsága, egy listatagsága vagy egy eseménye —
 * és ezek közül több olyan hely írja, ami nem is tud a szegmensekről.
 *
 * Ezért a belépést ÖSSZEHASONLÍTÁS adja: a szegmens jelenlegi tagságát a `JourneyTrigger`
 * kapuihoz mérjük, és aki még nincs benne (nincs aktív futása, és az újrabelépési szabály
 * engedi), az belép. Ez tudatos közelítés: a belépés a KÖVETKEZŐ futásnál derül ki, nem a
 * pillanatában — egy percnyi késés egy marketing-automatizálásnál nem számít, egy hamis
 * „belépés" viszont fölös levelet küld.
 *
 * ## Miért kellett ez a parancs
 *
 * A `FireJourneyEvent::segmentEntered()` megvolt, a `TriggerType::SegmentEntered` is, a
 * docblock erre a parancsra hivatkozott — de a parancs NEM LÉTEZETT (`artisan list` →
 * csak `journeys:tick`). A `segment_entered` triggerű journey élesben soha nem tüzelt.
 * Ugyanaz a hibaosztály, mint a B2-ben a `VariantWinner`: megvolt, működött, senki nem
 * hívta. Egy trigger, amit csak a teszt hív, nem trigger.
 */
final class DetectSegmentEntriesCommand extends Command
{
    protected $signature = 'journeys:detect-segment-entries';

    protected $description = 'A `segment_entered` triggerű journey-k beléptetése a szegmens jelenlegi tagságából.';

    public function handle(WorkspaceContext $context, FireJourneyEvent $fire): int
    {
        $entered = 0;
        $checked = 0;

        // A HÍVÓ KONTEXTUSÁT VISSZAADJUK, nem eldobjuk — lásd a `TickJourneysCommand`
        // ugyanilyen indoklását.
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $fire, &$entered, &$checked): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $journeys = Journey::query()
                        ->where('status', 'active')
                        ->where('trigger_type', TriggerType::SegmentEntered->value)
                        ->get();

                    foreach ($journeys as $journey) {
                        $checked++;

                        // A CÉLZOTT SZEGMENS a journey trigger-configjából. A típusos `uuid`
                        // oszlopra nem-uuid értékkel lekérdezni nem „nem talál", hanem
                        // `SQLSTATE[22P02]` — ezért a formátumot előbb nézzük.
                        $uuid = (string) data_get((array) $journey->trigger_config, 'segment', '');

                        if (! Str::isUuid($uuid)) {
                            continue;
                        }

                        $segment = Segment::query()->where('uuid', $uuid)->first();

                        if ($segment === null) {
                            continue;
                        }

                        try {
                            $entered += $fire->segmentEntered($segment);
                        } catch (Throwable $e) {
                            // EGY HIBÁS SZEGMENS-DEFINÍCIÓ nem állítja meg a többi journey-t.
                            $this->warn(sprintf(
                                'A(z) %s journey szegmens-vizsgálata elhasalt: %s',
                                (string) $journey->uuid,
                                $e::class
                            ));
                        }
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Vizsgált journey: {$checked}, új belépés: {$entered}");

        return self::SUCCESS;
    }
}
