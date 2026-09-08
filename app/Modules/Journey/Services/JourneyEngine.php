<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Journey\Enums\StepType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyRunStep;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Template\Models\Template;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A JOURNEY MOTOR (68., 70. kritérium).
 *
 * ## A MOTOR LÉPTET, NEM FUT
 *
 * Egy journey napokig–hetekig tart: „regisztráció után 3 nap, ha nem vásárolt, küldj
 * emlékeztetőt". Ez nem tarthat egy folyamatban egy `sleep()`-pel — a worker újraindul, a
 * deploy lecseréli a kódot. A futás állapota az adatbázisban van, a motor pedig minden
 * tickben megkérdezi: hol áll, mi a következő, esedékes-e.
 *
 * ## AZ IDEMPOTENCIA ADATBÁZIS-SZINTŰ
 *
 * A tick ütemezőből jön, tehát ISMÉTLŐDHET. „Kétszeri tick = egy hatás" (68. kritérium)
 * nem valósítható meg alkalmazás-szintű ellenőrzéssel: két párhuzamos tick között az
 * „megnéztem, még nem futott" és a „lefuttattam" közé befér a másik.
 *
 * Ezért a lépés végrehajtásának ELŐFELTÉTELE egy `insertOrIgnore` a
 * `journey_run_steps` egyedi kulcsára. Aki beírta a sort, az futtat; aki nem tudta beírni,
 * az KILÉP. A hatás így akkor is egyszer történik meg, ha három worker egyszerre ébred.
 *
 * A `lockForUpdate` ezt kiegészíti: a futás állapotának előreléptetése sorosított, tehát
 * két tick nem tud két különböző következő lépésre állni.
 *
 * ## AZ ÓRA KÍVÜLRŐL JÖN
 *
 * A motor `now()`-t használ, és a tesztben a `travel()` állítja. A motor semmit nem tud
 * arról, hogy „valódi" idő-e — egy háromnapos várakozást nem lehet háromnapos teszttel
 * ellenőrizni (68. kritérium: determinisztikus FAKE ÓRÁN).
 */
final class JourneyEngine
{
    /**
     * Egy tickben ennyi lépést tesz meg EGY futás.
     *
     * Nem végtelen ciklus: egy `condition` → `add_tag` → `condition` lánc azonnal
     * végrehajtható lépésekből is állhat, de egy körben nem visszük végig az egészet —
     * különben egy hosszú lánc egyetlen futás miatt blokkolná a többit.
     */
    private const MAX_STEPS_PER_TICK = 20;

    /**
     * A futás ÖSSZES lépésének felső korlátja.
     *
     * A végtelen ciklus ellen: egy condition, ami magára mutat, örökké futna, és a
     * worker-sort tömné meg. A korlát elérése HIBA, nem csendes megállás — a tenantnak
     * tudnia kell, hogy a journey-je hibás.
     */
    private const MAX_STEPS_TOTAL = 500;

    public function __construct(
        private readonly SegmentEngine $segments,
        private readonly VentPayClient $ventPay,
    ) {}

    /**
     * EGY FUTÁS LÉPTETÉSE.
     *
     * @return int a megtett lépések száma
     */
    public function tick(JourneyRun $run): int
    {
        $taken = 0;

        while ($taken < self::MAX_STEPS_PER_TICK) {
            $run = $run->fresh();

            if ($run === null || $run->isFinished()) {
                break;
            }

            // A KILÉPÉSI FELTÉTELEK MINDEN LÉPÉS ELŐTT (70. kritérium).
            //
            // Nem csak belépéskor: egy hetekig futó journey közben a kontakt leiratkozhat,
            // elérheti a célt, vagy a tenant elveszítheti a jogosultságát. A levél, ami
            // MÁR elindult, nem hívható vissza — de a következő nem mehet ki.
            if ($this->shouldExit($run)) {
                break;
            }

            if (! $this->isDue($run)) {
                break;
            }

            $step = $run->currentStep;

            if ($step === null) {
                // A LÁNC VÉGE implicit befejezés: a kontakt végigment a journey-n.
                $this->finish($run, 'completed', null);

                break;
            }

            if (! $this->execute($run, $step)) {
                break;
            }

            $taken++;
        }

        return $taken;
    }

    /**
     * EGY LÉPÉS VÉGREHAJTÁSA — idempotensen.
     *
     * @return bool folytatható-e a futás ebben a tickben
     */
    private function execute(JourneyRun $run, JourneyStep $step): bool
    {
        return DB::transaction(function () use ($run, $step): bool {
            // SOR-ZÁROLÁS: a futás állapotának előreléptetése sorosított.
            $locked = JourneyRun::query()->whereKey($run->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isFinished()) {
                return false;
            }

            // A ZÁROLÁS UTÁN ÚJRA megnézzük, hol áll: egy másik tick közben előreléptethette.
            if ((int) $locked->current_step_id !== (int) $step->getKey()) {
                return false;
            }

            if ((int) $locked->steps_taken >= self::MAX_STEPS_TOTAL) {
                Log::warning('Journey-futás elérte a lépés-korlátot — valószínűleg körkörös a lánc.', [
                    'run' => (string) $locked->uuid,
                    'journey' => (string) $locked->journey_id,
                ]);

                $this->finish($locked, 'failed', 'step_limit');

                return false;
            }

            /*
             | AZ IDEMPOTENCIA-ZÁR.
             |
             | Aki be tudta írni a sort, az futtat. Aki nem (mert egy párhuzamos tick
             | megelőzte), az KILÉP — a hatás egyszer történik meg. Ez nem az alkalmazás
             | jóindulatán múlik: az egyedi index dönt.
             */
            $iteration = (int) JourneyRunStep::query()
                ->where('journey_run_id', $locked->getKey())
                ->where('journey_step_id', $step->getKey())
                ->max('iteration');

            try {
                $claim = JourneyRunStep::query()->create([
                    'journey_run_id' => $locked->getKey(),
                    'journey_step_id' => $step->getKey(),
                    'iteration' => $iteration + 1,
                    'executed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Egy PÁRHUZAMOS tick megelőzött: ő futtat, mi kilépünk. A hatás egyszer
                // történik meg — és ezt az EGYEDI INDEX dönti el, nem egy alkalmazás-szintű
                // „megnéztem, még nem futott" ellenőrzés, ami két kérés között befér.
                return false;
            }

            $outcome = $this->perform($locked, $step, $claim);

            $locked->increment('steps_taken');

            return $outcome;
        });
    }

    /**
     * A LÉPÉS HATÁSA.
     *
     * @return bool folytatható-e a futás ebben a tickben
     */
    private function perform(JourneyRun $run, JourneyStep $step, JourneyRunStep $claim): bool
    {
        $config = (array) $step->config;

        return match ($step->stepType()) {
            StepType::Wait => $this->wait($run, $step, $config),
            StepType::Condition => $this->condition($run, $step, $config, $claim),
            StepType::SendEmail => $this->sendEmail($run, $step, $config, $claim),
            StepType::AddTag, StepType::RemoveTag => $this->tag($run, $step, $config),
            // AZ EXPLICIT KILÉPÉS lezárja a futást, tehát a tick sem folytatódik.
            StepType::Exit => $this->exitStep($run),
        };
    }

    private function exitStep(JourneyRun $run): bool
    {
        $this->finish($run, 'exited', 'step_exit');

        return false;
    }

    /** @param array<string, mixed> $config */
    private function wait(JourneyRun $run, JourneyStep $step, array $config): bool
    {
        // A VÁRAKOZÁS a futást FELFÜGGESZTI: `waiting` állapot + `resume_at`. A motor a
        // következő tickben az AKTUÁLIS időhöz méri — a tesztben a fake órához.
        $minutes = max(1, (int) ($config['minutes'] ?? 60));

        // A `wait` a KÖVETKEZŐ lépésre állítja a futást, és felfüggeszti: a várakozás nem
        // önmagában cél, hanem a következő lépés előtti szünet. Ha nincs következő lépés, a
        // várakozásnak sincs értelme — a futás befejeződik.
        if ($step->next_step_id === null) {
            $this->finish($run, 'completed', 'chain_end');

            return false;
        }

        $run->forceFill([
            'status' => 'waiting',
            'resume_at' => now()->addMinutes($minutes),
            'current_step_id' => $step->next_step_id,
        ])->save();

        // A tick ITT megáll: a következő lépés a `resume_at` után jön.
        return false;
    }

    /** @param array<string, mixed> $config */
    private function condition(JourneyRun $run, JourneyStep $step, array $config, JourneyRunStep $claim): bool
    {
        $matches = $this->evaluate($run, $config);

        // A KÉT ÁG: az „igaz" a lánc folytatása, a „hamis" az `else_step`. Ha a hamis ág
        // nincs megadva, a futás BEFEJEZŐDIK — nem esik át csendben az igaz ágra.
        $next = $matches ? $step->next_step_id : $step->else_step_id;

        // A KIMENET a MI sorunkra íródik (a `$claim`-re), nem egy „legutolsó" keresésre: a
        // Postgres az `UPDATE ... LIMIT`-et nem is támogatja, és egy ciklikus journey-ben
        // több sor is tartozik ugyanahhoz a lépéshez.
        $claim->forceFill(['outcome' => $matches ? 'true' : 'false'])->save();

        if ($next === null) {
            $this->finish($run, 'completed', $matches ? 'branch_end' : 'else_branch_end');

            return false;
        }

        $run->forceFill(['current_step_id' => $next, 'status' => 'active', 'resume_at' => null])->save();

        return true;
    }

    /**
     * A FELTÉTEL kiértékelése.
     *
     * Három fajta: szegmens-tagság (a B1 motorján), esemény bekövetkezte (nyitás, klikk),
     * és a futás kontextusa (a trigger payloadja). Mindhárom DETERMINISZTIKUS: ugyanaz az
     * adat ugyanazt az eredményt adja.
     *
     * @param  array<string, mixed>  $config
     */
    private function evaluate(JourneyRun $run, array $config): bool
    {
        $type = (string) ($config['type'] ?? '');

        return match ($type) {
            'in_segment' => $this->inSegment($run, (string) ($config['segment'] ?? '')),
            'opened_email', 'clicked_email' => $this->hadEvent(
                $run,
                $type === 'opened_email' ? 'opened' : 'clicked',
                (int) ($config['within_hours'] ?? 168),
            ),
            'has_tag' => $this->hasTag($run, (string) ($config['tag'] ?? '')),
            'context_equals' => (string) data_get((array) $run->context, (string) ($config['key'] ?? ''), '')
                === (string) ($config['value'] ?? ''),
            // AZ ISMERETLEN FELTÉTEL HAMIS, nem igaz.
            //
            // Fail-closed: egy elgépelt feltétel-típus így az `else` ágra vezet, nem a
            // levélküldésre. A fordított alapérték azt jelentené, hogy egy hibás
            // konfiguráció TÖBB levelet küld.
            default => false,
        };
    }

    private function inSegment(JourneyRun $run, string $segmentUuid): bool
    {
        $segment = Segment::query()->where('uuid', $segmentUuid)->first();

        if ($segment === null) {
            return false;
        }

        return $this->segments->query((array) $segment->definition)
            ->where('contacts.email', $run->email)
            ->exists();
    }

    private function hadEvent(JourneyRun $run, string $type, int $withinHours): bool
    {
        return MessageEvent::query()
            ->where('type', $type)
            // KIS/NAGYBETŰ-FÜGGETLEN: a `contacts.email` a `listKey()` alakja, a
            // `message_events.recipient` viszont a helyi részt érintetlenül hagyja.
            ->whereRaw('lower(recipient) = ?', [(string) $run->email])
            ->where('occurred_at', '>=', now()->subHours(max(1, $withinHours)))
            ->exists();
    }

    private function hasTag(JourneyRun $run, string $tagUuid): bool
    {
        $contact = $run->contact;

        if ($contact === null) {
            return false;
        }

        return $contact->tags()->where('contact_tags.uuid', $tagUuid)->exists();
    }

    /** @param array<string, mixed> $config */
    private function sendEmail(JourneyRun $run, JourneyStep $step, array $config, JourneyRunStep $claim): bool
    {
        // AZ UUID FORMÁTUMÁT ELŐBB: a `uuid` oszlop típusos, tehát egy elgépelt vagy hibás
        // hivatkozás `invalid input syntax for type uuid`-del elhasalna. Az így keletkező
        // `QueryException` a `send_error` ágra vinné a futást, és a tenant „küldési hibát"
        // látna egy hiányzó sablon helyett — a naplóban pedig egy nyers SQL-kivétel állna.
        $uuid = (string) ($config['template'] ?? '');

        $template = Str::isUuid($uuid)
            ? Template::query()->where('uuid', $uuid)->first()
            : null;

        if ($template === null) {
            $this->finish($run, 'failed', 'template_missing');

            return false;
        }

        try {
            $result = app(SendMessage::class)([
                /*
                 | A `journey` CSATORNA — a tranzakciós ÉS a kampány KÖZÖTT.
                 |
                 | A `Channel::queuePriority()` szerint: transactional (0) > journey (1) >
                 | campaign (2). Ez szándékos: a journey levele időérzékenyebb egy
                 | hírlevélnél (egy „elhagyott kosár" emlékeztető három nap múlva
                 | értéktelen), de sosem előzheti meg a jelszó-visszaállítót.
                 |
                 | A consent-követelmény ugyanaz, mint a marketingnél
                 | (`Channel::consentScope()`).
                 */
                'channel' => 'journey',
                'from_email' => (string) ($config['from_email'] ?? config('mail.from.address')),
                'from_name' => $config['from_name'] ?? null,
                'to' => [(string) $run->email],
                'subject' => (string) ($config['subject'] ?? $template->subject ?? ''),
                'html' => (string) $template->html,
                'text' => (string) $template->text,
                'tags' => ['journey:'.$run->journey_id, 'journey_step:'.$step->uuid],
                /*
                 | AZ IDEMPOTENCIA-KULCS a futás és a lépés párosa.
                 |
                 | A `journey_run_steps` egyedi kulcsa már megvédett a dupla
                 | végrehajtástól — ez a MÁSODIK réteg: ha a lépés-igénylés valaha
                 | elromlana, a levél akkor sem megy ki kétszer.
                 */
                'idempotency_key' => 'journey:'.$run->uuid.':'.$step->uuid,
            ]);

            $claim->forceFill([
                'message_uuid' => $result->message?->uuid,
                // A KAPU DÖNTÉSE: a riportnak meg kell tudnia mondani, miért nem kapta meg
                // valaki a journey-levelet — a `skipped` nem hiba, hanem a kapu munkája.
                'outcome' => $result->decision->allowed ? 'sent' : 'skipped',
            ])->save();
        } catch (Throwable $e) {
            // EGY LÉPÉS HIBÁJA a FUTÁST jelöli meg, nem dobja el a többit: egy hibás
            // sablon nem állíthatja meg az összes journey-t.
            report($e);

            $this->finish($run, 'failed', 'send_error');

            return false;
        }

        return $this->advance($run, $step);
    }

    /** @param array<string, mixed> $config */
    private function tag(JourneyRun $run, JourneyStep $step, array $config): bool
    {
        $contact = $run->contact;
        $tag = ContactTag::query()->where('uuid', (string) ($config['tag'] ?? ''))->first();

        if ($contact !== null && $tag !== null) {
            $step->stepType() === StepType::AddTag
                ? $contact->tags()->syncWithoutDetaching([$tag->getKey()])
                : $contact->tags()->detach($tag->getKey());
        }

        return $this->advance($run, $step);
    }

    /**
     * ELŐRELÉPTETÉS a következő lépésre — vagy BEFEJEZÉS, ha nincs több.
     *
     * A lánc végét AZONNAL zárjuk, nem a következő tickben. Enélkül a futás egy percig
     * `active` maradt `current_step_id = null`-lal: a felületen „fut" állapotban látszott
     * volna egy már befejezett journey, és a riport `completed_count`-ja egy tickkel
     * késett. Egy ütemező-kimaradás alatt ez az állapot akármeddig eltarthat.
     *
     * @return bool folytatható-e a futás ebben a tickben
     */
    private function advance(JourneyRun $run, JourneyStep $step): bool
    {
        if ($step->next_step_id === null) {
            $this->finish($run, 'completed', 'chain_end');

            return false;
        }

        $run->forceFill([
            'current_step_id' => $step->next_step_id,
            'status' => 'active',
            'resume_at' => null,
        ])->save();

        return true;
    }

    /**
     * KILÉPÉSI FELTÉTELEK (70. kritérium).
     *
     * Sorrend számít: a leiratkozás a legerősebb, mert az az ÉRINTETT akarata. A cél
     * elérése siker, a jogosultság elvesztése üzemeltetési ok — a riportnak mindhármat
     * külön kell tudnia mutatni.
     */
    private function shouldExit(JourneyRun $run): bool
    {
        // 1. LEIRATKOZÁS / SUPPRESSION — az érintett akarata mindent megelőz.
        //
        // Nem csak a levélküldést állítjuk le: a címkézés és a szegmens-mozgatás is
        // profilalkotás, amit egy leiratkozott embernél nem folytatunk.
        $suppressed = Suppression::query()
            ->where('email', $run->email)
            ->get()
            ->contains(fn (Suppression $row) => $row->isActive());

        if ($suppressed) {
            $this->finish($run, 'exited', 'unsubscribed');

            return true;
        }

        // 2. A JOURNEY már nem aktív (a tenant leállította vagy archiválta).
        $journey = $run->journey;

        if ($journey === null || ! $journey->isActive()) {
            $this->finish($run, 'exited', 'journey_archived');

            return true;
        }

        // 3. ENTITLEMENT (70. kritérium): a `journeys` funkció a csomag része-e.
        //
        // Futás KÖZBEN is ellenőrizzük, nem csak belépéskor: egy lefokozott csomag nem
        // futtathat tovább automatizálást hetekig. A levél, ami már elindult, nem hívható
        // vissza — a következő nem mehet ki.
        if (! $this->ventPay->hasEntitlement((int) $run->workspace_id, 'journeys')) {
            $this->finish($run, 'exited', 'entitlement_lost');

            return true;
        }

        // 4. A CÉL elérve — ez a siker.
        if ($this->goalReached($journey, $run)) {
            $this->finish($run, 'completed', 'goal_reached');

            return true;
        }

        return false;
    }

    private function goalReached(Journey $journey, JourneyRun $run): bool
    {
        return match ((string) $journey->goal_type) {
            'in_segment' => $this->inSegment($run, (string) data_get((array) $journey->goal_config, 'segment', '')),
            'clicked_email' => $this->hadEvent($run, 'clicked', (int) data_get((array) $journey->goal_config, 'within_hours', 720)),
            'has_tag' => $this->hasTag($run, (string) data_get((array) $journey->goal_config, 'tag', '')),
            default => false,
        };
    }

    private function isDue(JourneyRun $run): bool
    {
        return $run->resume_at === null || $run->resume_at->lessThanOrEqualTo(now());
    }

    private function finish(JourneyRun $run, string $status, ?string $reason): bool
    {
        $run->forceFill([
            'status' => $status,
            'exit_reason' => $reason,
            'resume_at' => null,
            'finished_at' => now(),
        ])->save();

        $journey = $run->journey;

        if ($journey !== null) {
            $status === 'completed'
                ? $journey->increment('completed_count')
                : $journey->increment('exited_count');
        }

        return true;
    }
}
