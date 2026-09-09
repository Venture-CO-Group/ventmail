<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;

/**
 * JOURNEY-RIPORT (72., 73. kritérium).
 *
 * ## KÉT különböző dolgot mér, és a kettőt nem keverjük
 *
 * - a FUTÁSOK állapotát (belépett / befejezte / kilépett / fut) — ez a `journey_runs`-ból
 *   jön, mert ez a journey saját állapotgépe, nem levél-esemény;
 * - a LEVELEK teljesítményét (kézbesítve / nyitva / klikkelve) — ez a `message_events`-ből,
 *   ugyanabból a forrásból, mint a kampány-riport és a platform-napló.
 *
 * A kettő összemosása lenne a klasszikus hiba: „a journey 80%-os nyitási arányú" — de mihez
 * képest? A belépőkhöz, akik közül a fele még nem is jutott el a levélig? A kézbesítéshez?
 * Ezért a befejezési arány a BELÉPÉSRE, a nyitási arány a KÉZBESÍTETTRE vetül, és a
 * riportban külön sorban áll.
 *
 * ## A LÉPÉSENKÉNTI bontás a lemorzsolódás helyét mutatja
 *
 * Egy ötlépéses onboarding journey-nél a kérdés nem az, hogy „jó-e", hanem hogy MELYIK
 * lépésnél állnak meg az emberek. Ezért a `journey_step:<uuid>` tag lépésenként szűr — a
 * journey-tag MELLETT, ÉS-kapcsolatban.
 */
final class JourneyAnalytics
{
    public function __construct(private readonly EventCounters $counters) {}

    /** A journey TAGJE — a motor ezt írja minden üzenetre (`JourneyEngine::sendEmail`). */
    public static function tag(Journey $journey): string
    {
        return 'journey:'.$journey->getKey();
    }

    /**
     * @return array<string, int|float|string>
     */
    public function summary(Journey $journey): array
    {
        $counts = $this->counters->forTags([self::tag($journey)]);

        $entered = (int) $journey->entered_count;
        $completed = (int) $journey->completed_count;
        $exited = (int) $journey->exited_count;

        return array_merge(
            [
                'journey' => (string) $journey->name,
                'journey_uuid' => (string) $journey->uuid,
                'status' => (string) $journey->status,
                'entered' => $entered,
                'completed' => $completed,
                'exited' => $exited,
                // A FUTÓ futások száma NEM számlálóból jön: az állapot maga a válasz, és
                // egy számláló itt csak elcsúszni tudna (a futás állapota változik, a
                // számláló nem tudja, melyik irányba).
                'active' => JourneyRun::query()
                    ->where('journey_id', $journey->getKey())
                    ->whereIn('status', ['active', 'waiting'])
                    ->count(),
                'failed' => JourneyRun::query()
                    ->where('journey_id', $journey->getKey())
                    ->where('status', 'failed')
                    ->count(),
                // A BEFEJEZÉSI ARÁNY a BELÉPÉSRE vetül, nem a kézbesítésre.
                'completion_rate' => Rates::percent($completed, $entered),
                'exit_rate' => Rates::percent($exited, $entered),
            ],
            $counts,
            $this->counters->rates($counts),
        );
    }

    /**
     * LÉPÉSENKÉNTI bontás — a lemorzsolódás helye.
     *
     * @return list<array<string, int|float|string>>
     */
    public function byStep(Journey $journey): array
    {
        $rows = [];

        foreach ($journey->steps()->get() as $step) {
            // CSAK a levélküldő lépéseknek van levél-statisztikája. Egy `wait` vagy
            // `add_tag` lépésre a nulla nem információ, hanem zaj a riportban.
            if ((string) $step->type !== 'send_email') {
                continue;
            }

            $counts = $this->counters->forTags([
                self::tag($journey),
                'journey_step:'.$step->uuid,
            ]);

            $rows[] = array_merge(
                [
                    'step_uuid' => (string) $step->uuid,
                    'position' => (int) $step->position,
                    'type' => (string) $step->type,
                    // A LÉPÉS VÉGREHAJTÁSAI: ez a `journey_run_steps`-ből jön, és attól
                    // KÜLÖNBÖZIK, hogy hány levél ment ki — a kapu-elutasítás is
                    // végrehajtás, csak `skipped` kimenettel.
                    'executed' => $step->runSteps()->count(),
                ],
                $counts,
                $this->counters->rates($counts),
            );
        }

        return $rows;
    }

    /**
     * A KILÉPÉSI OKOK bontása — a 70. kritérium riport-oldala.
     *
     * „Miért esnek ki az emberek?" A leiratkozás, a cél elérése és a jogosultság-vesztés
     * három teljesen más történet, és a riportnak külön kell mutatnia őket.
     *
     * @return array<string, int>
     */
    public function exitReasons(Journey $journey): array
    {
        return JourneyRun::query()
            ->where('journey_id', $journey->getKey())
            ->whereNotNull('exit_reason')
            ->selectRaw('exit_reason, count(*) as total')
            ->groupBy('exit_reason')
            ->orderByDesc('total')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->exit_reason => (int) $row->total])
            ->all();
    }
}
