<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Consent\Models\Consent;
use App\Modules\Journey\Enums\StepType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyRunStep;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\MergeVariables;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
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

    /**
     * Hány hibás lépés után adjuk fel véglegesen a futást.
     *
     * Négy kísérlet (1 + 2 + 4 + 8 perc): egy átmeneti zavar ennyi idő alatt rendeződik,
     * egy konfigurációs hiba viszont nem — és a negyedik után a futás `failed`, nem
     * örökké esedékes.
     */
    private const MAX_FAILURES = 4;

    /** A `wait` lépés felső korlátja: egy év. Lásd a `wait()` indoklását. */
    public const MAX_WAIT_MINUTES = 525600;

    /**
     * Az ESEMÉNYRE VÁRÓ `wait` ennyi időnként néz rá, megtörtént-e a nyitás / kattintás.
     * Nem esemény-vezérelt: a futás a saját ütemében, a meglévő tickben ellenőriz.
     */
    public const EVENT_POLL_MINUTES = 15;

    public function __construct(
        private readonly EntitlementChecker $checker,
        private readonly JourneyConditions $conditions,
    ) {}

    /**
     * EGY FUTÁS LÉPTETÉSE.
     *
     * @return int a megtett lépések száma
     */
    public function tick(JourneyRun $run): int
    {
        $taken = 0;

        // A KIINDULÓ lépésszám: a `taken` ehhez képest mér (lásd a ciklusban).
        $before = JourneyRunStep::query()->where('journey_run_id', $run->getKey())->count();

        while ($taken < self::MAX_STEPS_PER_TICK) {
            $run = $run->fresh();

            if ($run === null || $run->isFinished()) {
                break;
            }

            /*
             | A KILÉPÉSI FELTÉTELEK MINDEN LÉPÉS ELŐTT (70. kritérium).
             |
             | Nem csak belépéskor: egy hetekig futó journey közben a kontakt leiratkozhat,
             | elérheti a célt, vagy a tenant elveszítheti a jogosultságát. A levél, ami MÁR
             | elindult, nem hívható vissza — de a következő nem mehet ki.
             |
             | A HIBA ITT IS KÉSLELTET: a kilépés-vizsgálat maga is elhasalhat (olvashatatlan
             | journey, adatbázis-zavar), és a kivétel korábban kiszökött a `tick()`-ből —
             | onnan pedig a futás örökké esedékes maradt.
             */
            try {
                if ($this->shouldExit($run)) {
                    break;
                }
            } catch (Throwable $e) {
                $this->backOff($run, $e);

                break;
            }

            if (! $this->isDue($run)) {
                break;
            }

            $step = $run->currentStep;

            if ($step === null) {
                /*
                 | A `current_step_id` HIÁNYA KÉT DOLGOT jelenthet, és a kettő NEM ugyanaz.
                 |
                 | (a) A lánc vége — de azt az `advance()` már azonnal lezárja, tehát ide
                 |     normál úton nem jutunk el.
                 | (b) A lépést KITÖRÖLTÉK a futás alól: a FK `nullOnDelete`, tehát a
                 |     marketinges egy szerkesztéssel `NULL`-ra állítja a félúton lévő
                 |     futások `current_step_id`-jét.
                 |
                 | A (b) eset korábban `exit_reason = null`-lal `completed`-re zárt, vagyis
                 | a riportban SIKERKÉNT jelent meg egy félbeszakadt automatizálás. A
                 | `step_deleted` ok az, ami ezt megkülönböztethetővé teszi.
                 */
                $this->finish($run, 'exited', 'step_deleted');

                break;
            }

            /*
             | A LÉPÉS HIBÁJA NEM TEHETI ÖRÖKKÉ ESEDÉKESSÉ A FUTÁST.
             |
             | Mérve: egy `add_tag` lépés `config.tag = 'vip'`-pel `QueryException 22P02`-t
             | dobott (a `uuid` oszlop típusos), a kivétel KISZÖKÖTT a `tick()`-ből, és a
             | futás `status=active`, `resume_at=NULL`, `steps_taken=0` állapotban maradt —
             | vagyis TOVÁBBRA IS esedékes. A percenkénti ütemező így örökké újradobta:
             | futásonként napi 1440 hiba, a futás soha nem lett `failed`, és a
             | `MAX_STEPS_TOTAL` sem ütött be, mert a számláló nem nőtt.
             |
             | A leggyakoribb éles út nem is elgépelés: `goal_type = 'has_tag'` a séma ÜRES
             | `goal_config`-jával a `shouldExit()`-ből dobja ugyanezt — tehát MINDEN lépés
             | előtt, az adott journey MINDEN futására.
             |
             | Ezért: a hiba KÉSLELTET és SZÁMOL. Egy pillanatnyi deadlock vagy
             | Redis-kimaradás után a futás visszatér (nem öljük meg félúton egy hetekig
             | futó automatizálást), egy konfigurációs hiba viszont `MAX_FAILURES` után
             | véglegesen `failed` lesz — hangosan, a naplóban okkal.
             */
            try {
                $continue = $this->execute($run, $step);
            } catch (Throwable $e) {
                $this->backOff($run, $e);

                break;
            }

            // A SZÁMLÁLÓ A VÉGREHAJTOTT LÉPÉSEKET számolja, nem a „folytatható-e" flaget.
            //
            // Mérve: 200 futáson a parancs `Futás: 200, lépés: 0`-t írt ki, miközben 200
            // `wait` lépés valóban lefutott — mert minden felfüggesztő és záró lépés
            // `false`-t ad vissza. Egy csak `wait`-ekből álló journey így ÖRÖKKÉ nullát
            // jelentett, és az üzemeltető egyetlen jelzőszáma szisztematikusan hamis volt.
            $executed = JourneyRunStep::query()->where('journey_run_id', $run->getKey())->count();

            if ($executed > $before) {
                $taken += $executed - $before;
                $before = $executed;
            }

            if (! $continue) {
                break;
            }
        }

        return $taken;
    }

    /**
     * A HIBÁS LÉPÉS KÉSLELTETÉSE, majd — ismétlődés után — a futás lezárása.
     *
     * A visszatartás EXPONENCIÁLIS: az első hiba egy perc, a második kettő, a harmadik
     * négy. Egy átmeneti zavar így magától rendeződik, egy konfigurációs hiba viszont nem
     * pörgeti a sort percenként.
     */
    private function backOff(JourneyRun $run, Throwable $e): void
    {
        $failures = (int) $run->failure_count + 1;

        Log::warning('Journey-lépés hibára futott', [
            'run' => (string) $run->uuid,
            'journey' => (int) $run->journey_id,
            'step' => (int) $run->current_step_id,
            'failures' => $failures,
            // A KIVÉTEL TÍPUSA utazik, nem az üzenete: az üzenet a nyers SQL-t és benne a
            // CÍMET is hordozhatja, a napló pedig a retenciónk alatt áll.
            'exception' => $e::class,
        ]);

        if ($failures >= self::MAX_FAILURES) {
            $run->forceFill(['failure_count' => $failures])->save();
            $this->finish($run, 'failed', 'engine_error');

            return;
        }

        $run->forceFill([
            'failure_count' => $failures,
            'status' => 'waiting',
            'resume_at' => now()->addMinutes(2 ** ($failures - 1)),
        ])->save();
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

            /*
             | A LÉPÉS UGYANAHHOZ A JOURNEY-HEZ TARTOZIK.
             |
             | A `next_step_id` FK-ja csak a `journey_steps` táblára mutat, nem UGYANARRA a
             | journey-re. Mérve: a C journey `wait` lépését a B journey `add_tag` lépésére
             | láncoltam, és a C futása LEFUTTATTA B lépését — felkerült B címkéje, és a
             | `completed_count` a C-nél nőtt. A workspace-határ zárva volt (a `JourneyStep`
             | tenant-kötött), a JOURNEY-határ nyitva.
             |
             | Ma nincs builder API, tehát ez lappangó — de a B4 analitika ezekre a
             | számlálókra épül, és egy „miért kapta meg X a B kampány címkéjét?" kérdésre
             | nem lehet azt válaszolni, hogy „mert a C journey lánca átmutatott".
             */
            if ((int) $step->journey_id !== (int) $locked->journey_id) {
                Log::warning('Journey-lépés IDEGEN journey-hez tartozik — a futás lezárva.', [
                    'run' => (string) $locked->uuid,
                    'run_journey' => (int) $locked->journey_id,
                    'step_journey' => (int) $step->journey_id,
                ]);

                $this->finish($locked, 'failed', 'step_journey_mismatch');

                return false;
            }

            /*
             | A LÉPÉS A FUTÁS VERZIÓJÁHOZ TARTOZIK (v1.1, D5).
             |
             | Az aktív journey módosítása új verziót hoz; a bent lévő futás a SAJÁT verziója
             | lépésein halad. Egy verzió-határon átmutató lépés (kézi adat-babrálás, hibás
             | elágazás) nem futhat le csendben egy másik verzió levelével.
             */
            if ((int) $step->journey_version_id !== (int) $locked->journey_version_id) {
                $this->finish($locked, 'failed', 'step_version_mismatch');

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
             | A VÁSZON `wait` CSOMÓPONTJA a futást a SAJÁT csomópontján tartja (v1.1).
             |
             | A régi lánc a várakozás elején a KÖVETKEZŐ lépésre állt — így a csomópont-
             | statisztikán a várakozók a várakozás UTÁNI lépésnél látszottak volna. Itt a futás
             | a `wait`-en áll, amíg le nem telik (vagy az esemény be nem következik), és a lépés
             | csak a továbblépéskor kerül a `journey_run_steps`-be: a „továbblépett" szám így
             | pontos, és a várakozás alatti ellenőrzések nem égetik a lépés-korlátot.
             */
            $waitHandle = null;

            if ($step->isGraphStep() && $step->stepType() === StepType::Wait) {
                $waitHandle = $this->graphWait($locked, $step);

                if ($waitHandle === null) {
                    return false;
                }
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

            $outcome = $waitHandle !== null
                ? $this->leaveWait($locked, $step, $claim, $waitHandle)
                : $this->perform($locked, $step, $claim);

            $locked->increment('steps_taken');

            // A „TOVÁBBLÉPETT" ELŐRE ÖSSZESÍTETT számlálója — a csomópont-statisztika nem
            // számol a futás-táblán. Query-builder, nem modell: a lépés auditált, és egy
            // végrehajtás nem admin-művelet.
            JourneyStep::query()->whereKey($step->getKey())->increment('executed_count');

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
            StepType::Split => $this->split($run, $step, $config, $claim),
            StepType::AbSplit => $this->abSplit($run, $step, $config, $claim),
            StepType::UpdateContact => $this->updateContact($run, $step, $config),
            StepType::ListMembership => $this->listMembership($run, $step, $config),
            StepType::Webhook => $this->webhook($run, $step, $config, $claim),
            StepType::Goal => $this->goalStep($run),
        };
    }

    /**
     * A VÁSZON `wait` csomópontja: letelt-e (vagy bekövetkezett-e az esemény)?
     *
     * @return string|null a kimenet (`out` | `timeout`), vagy `null`, ha a futás még vár
     */
    private function graphWait(JourneyRun $run, JourneyStep $step): ?string
    {
        $config = (array) $step->config;
        $event = ($config['mode'] ?? 'time') === 'event';
        $context = (array) $run->context;
        $marker = is_array($context['_wait'] ?? null) ? $context['_wait'] : [];

        // ÉRKEZÉS a csomópontra: a jelölő rögzíti, mióta vár és meddig.
        if ((int) ($marker['step'] ?? 0) !== (int) $step->getKey()) {
            $minutes = min(self::MAX_WAIT_MINUTES, max(1, (int) ($event ? ($config['timeout_minutes'] ?? 0) : ($config['minutes'] ?? 60))));
            $until = now()->addMinutes($minutes);

            $context['_wait'] = ['step' => (int) $step->getKey(), 'since' => now()->toIso8601String(), 'until' => $until->toIso8601String()];

            $run->forceFill([
                'context' => $context,
                'status' => 'waiting',
                'resume_at' => $event ? min($until, now()->addMinutes(self::EVENT_POLL_MINUTES)) : $until,
            ])->save();

            return null;
        }

        $until = CarbonImmutable::parse((string) $marker['until']);

        if ($event && $this->conditions->hadEvent((string) $run->email, (string) ($config['event'] ?? ''), CarbonImmutable::parse((string) $marker['since']))) {
            return 'out';
        }

        if (now()->greaterThanOrEqualTo($until)) {
            return $event ? 'timeout' : 'out';
        }

        $run->forceFill([
            'status' => 'waiting',
            'resume_at' => $event ? min($until, now()->addMinutes(self::EVENT_POLL_MINUTES)) : $until,
        ])->save();

        return null;
    }

    private function leaveWait(JourneyRun $run, JourneyStep $step, JourneyRunStep $claim, string $handle): bool
    {
        $context = (array) $run->context;
        unset($context['_wait']);

        $run->forceFill(['context' => $context])->save();
        $claim->forceFill(['outcome' => $handle])->save();

        return $this->advanceTo($run, $handle === 'timeout' ? $step->else_step_id : $step->next_step_id, 'chain_end');
    }

    /**
     * TÖBBÁGÚ ELÁGAZÁS: az első igaz feltétel ága, különben az `else`.
     *
     * @param  array<string, mixed>  $config
     */
    private function split(JourneyRun $run, JourneyStep $step, array $config, JourneyRunStep $claim): bool
    {
        $handle = 'else';

        foreach ((array) ($config['branches'] ?? []) as $branch) {
            if (is_array($branch) && $this->conditions->evaluate((array) ($branch['condition'] ?? []), (string) $run->email, $run->contact, (array) $run->context)) {
                $handle = (string) ($branch['key'] ?? 'else');

                break;
            }
        }

        $claim->forceFill(['outcome' => $handle])->save();

        return $this->advanceTo($run, $this->branchTarget($step, $handle), 'branch_end');
    }

    /**
     * A/B ÁGAK arányokkal — DETERMINISZTIKUS sorsolás.
     *
     * A futás és a lépés azonosítójának lenyomata dönt, nem `random_int()`: egy újrapróbált
     * tick (vagy a teszt-futtatás ugyanarra a kontaktra) UGYANAZT az ágat választja.
     *
     * @param  array<string, mixed>  $config
     */
    private function abSplit(JourneyRun $run, JourneyStep $step, array $config, JourneyRunStep $claim): bool
    {
        $handle = self::pickVariant((array) ($config['variants'] ?? []), (string) $run->uuid.'|'.(string) $step->uuid);

        $claim->forceFill(['outcome' => $handle])->save();

        return $this->advanceTo($run, $handle === null ? null : $this->branchTarget($step, $handle), 'branch_end');
    }

    /**
     * @param  array<int, mixed>  $variants
     */
    public static function pickVariant(array $variants, string $seed): ?string
    {
        $variants = array_values(array_filter($variants, static fn ($variant): bool => is_array($variant) && (int) ($variant['weight'] ?? 0) > 0));
        $total = array_sum(array_map(static fn (array $variant): int => (int) $variant['weight'], $variants));

        if ($total < 1) {
            return null;
        }

        $bucket = crc32($seed) % $total;

        foreach ($variants as $variant) {
            $bucket -= (int) $variant['weight'];

            if ($bucket < 0) {
                return (string) $variant['key'];
            }
        }

        return null;
    }

    /**
     * Az elágazás célja — CSAK ugyanannak a journey-nek és verziónak a lépése.
     *
     * A `branches` jsonb, tehát az adatbázis FK-ja és lánc-őre nem védi (a `next_step_id`
     * és az `else_step_id` igen). A cél ezért itt, a lépés saját journey-jére és verziójára
     * szűkítve oldódik fel — egy idegen id „nincs ilyen ág", nem átugrás.
     */
    private function branchTarget(JourneyStep $step, string $handle): ?int
    {
        $id = ((array) $step->branches)[$handle] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $exists = JourneyStep::query()
            ->whereKey((int) $id)
            ->where('journey_id', $step->journey_id)
            ->where('journey_version_id', $step->journey_version_id)
            ->exists();

        return $exists ? (int) $id : null;
    }

    /** @param array<string, mixed> $config */
    private function updateContact(JourneyRun $run, JourneyStep $step, array $config): bool
    {
        $operation = (string) ($config['operation'] ?? '');

        if (in_array($operation, ['add_tag', 'remove_tag'], true)) {
            return $this->applyTag($run, $step, (string) ($config['tag'] ?? ''), $operation === 'add_tag');
        }

        $field = trim((string) ($config['field'] ?? ''));

        if ($operation !== 'set_field' || $field === '') {
            $this->finish($run, 'failed', 'config_invalid');

            return false;
        }

        $contact = $run->contact;

        if ($contact !== null) {
            $value = mb_substr((string) ($config['value'] ?? ''), 0, 500);

            if (in_array($field, ['first_name', 'last_name'], true)) {
                $contact->setAttribute($field, $value);
            } else {
                $attributes = (array) $contact->getAttribute('attributes');
                $attributes[$field] = $value;
                $contact->setAttribute('attributes', $attributes);
            }

            $contact->save();
        }

        return $this->advance($run, $step);
    }

    /**
     * LISTA-TAGSÁG. A hozzáadás NEM ad hozzájárulást: a tagság `pending`, ha a kontaktnak
     * nincs használható marketing-consentje — a levelet úgyis a PreSendGate consent-kapuja
     * dönti el, de a lista-riport ne mutasson „feliratkozottat" jogalap nélkül.
     *
     * @param  array<string, mixed>  $config
     */
    private function listMembership(JourneyRun $run, JourneyStep $step, array $config): bool
    {
        $uuid = self::uuidOrNull((string) ($config['list'] ?? ''));
        $list = $uuid === null ? null : ContactList::query()->where('uuid', $uuid)->first();

        if ($list === null) {
            $this->finish($run, 'failed', 'config_invalid');

            return false;
        }

        $contact = $run->contact;

        if ($contact !== null) {
            if (($config['operation'] ?? 'add') === 'remove') {
                $contact->lists()->detach($list->getKey());
            } else {
                $consented = Consent::query()->usable()
                    ->where('email', (string) $run->email)
                    ->where('channel', Channel::Journey->consentScope())
                    ->exists();

                $contact->lists()->syncWithoutDetaching([
                    $list->getKey() => ['status' => $consented ? 'subscribed' : 'pending', 'subscribed_at' => $consented ? now() : null],
                ]);
            }
        }

        return $this->advance($run, $step);
    }

    /**
     * WEBHOOK — aláírt esemény egy REGISZTRÁLT végpontra.
     *
     * NEM nyers HTTP-hívás: a kézbesítést a Webhooks modul `DeliverWebhook` jobja végzi a
     * `webhooks` soron (SSRF-őr minden kísérletnél, időkorlát, HMAC-SHA256, exponenciális
     * újrapróbálás, dead-letter, replay — golden rule 5). A journey-lépés csak a kézbesítési
     * sort írja; egy lassú külső fogadó így nem tartja fel a tick-et és a levélküldést.
     *
     * @param  array<string, mixed>  $config
     */
    private function webhook(JourneyRun $run, JourneyStep $step, array $config, JourneyRunStep $claim): bool
    {
        $uuid = self::uuidOrNull((string) ($config['endpoint'] ?? ''));
        $endpoint = $uuid === null ? null : WebhookEndpoint::query()->where('uuid', $uuid)->where('active', true)->first();

        if ($endpoint === null) {
            $this->finish($run, 'failed', 'config_invalid');

            return false;
        }

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event_type' => 'journey.step',
            'payload' => [
                'event' => 'journey.step',
                'timestamp' => now()->getTimestamp(),
                'journey' => [
                    'id' => (string) $run->journey?->uuid,
                    'version' => $step->version?->number,
                    'node' => (string) $step->node_key,
                ],
                'run' => ['id' => (string) $run->uuid, 'iteration' => (int) $claim->iteration],
                'contact' => ['email' => (string) $run->email],
            ],
            'status' => 'pending',
            // A söprő parancs csak ennyi idő után néz rá (ugyanaz, mint a `WebhookDispatcher`-nél):
            // addig a commit után induló job dolga.
            'next_attempt_at' => now()->addSeconds(120),
        ]);

        DeliverWebhook::dispatch((int) $delivery->workspace_id, (string) $delivery->uuid);

        $claim->forceFill(['outcome' => 'queued'])->save();

        return $this->advance($run, $step);
    }

    /**
     * ELŐRELÉPTETÉS egy adott lépésre — vagy BEFEJEZÉS, ha nincs cél.
     */
    private function advanceTo(JourneyRun $run, ?int $target, string $endReason): bool
    {
        if ($target === null) {
            $this->finish($run, 'completed', $endReason);

            return false;
        }

        $run->forceFill(['current_step_id' => $target, 'status' => 'active', 'resume_at' => null])->save();

        return true;
    }

    /** A CÉL csomópont: a futás SIKERREL zárul. */
    private function goalStep(JourneyRun $run): bool
    {
        $this->finish($run, 'completed', 'goal_reached');

        return false;
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
        /*
         | A VÁRAKOZÁS FELSŐ KORLÁTJA — nem csak alsó.
         |
         | Mérve: `minutes = 9999999999` a `Carbon`-t `InvalidFormatException`-nel buktatta
         | el (`Could not parse '21039-12-07 …'`), és a futás `active` + `steps_taken = 0`
         | maradt, vagyis ORVOSOLHATATLANUL, percenként újra elhasalt. A `PHP_INT_MAX` még
         | rosszabb: túlcsordulás után `resume_at = now + 1 másodperc`, tehát a „végtelen
         | várakozás" AZONNALI lépéssé vált.
         |
         | Egy év a felső határ: ennél hosszabb várakozásnak egy marketing-automatizálásban
         | nincs értelme, és a korlát a hibát a KONFIGURÁCIÓ hibájává teszi, nem a motor
         | összeomlásává.
         */
        $minutes = min(self::MAX_WAIT_MINUTES, max(1, (int) ($config['minutes'] ?? 60)));

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
        // A KIÉRTÉKELÉS a `JourneyConditions`-ben él (v1.1): a teszt-futtatás ugyanazt a kódot
        // hívja, tehát ugyanazt az ágat mutatja, amit a valódi futás választana. AZ ISMERETLEN
        // FELTÉTEL továbbra is HAMIS (fail-closed).
        return $this->conditions->evaluate($config, (string) $run->email, $run->contact, (array) $run->context);
    }

    private function inSegment(JourneyRun $run, string $segmentUuid): bool
    {
        return $this->conditions->inSegment((string) $run->email, $segmentUuid);
    }

    private function hadEvent(JourneyRun $run, string $type, int $withinHours): bool
    {
        return $this->conditions->hadEvent((string) $run->email, $type, now()->subHours(max(1, $withinHours)));
    }

    private function hasTag(JourneyRun $run, string $tagUuid): bool
    {
        return $this->conditions->hasTag($run->contact, $tagUuid);
    }

    /**
     * A TÍPUSOS UUID-OSZLOPOK EGYETLEN KAPUJA.
     *
     * A Postgres `uuid` oszlopára nem-uuid sztringgel lekérdezni nem „nem talál" — hanem
     * `SQLSTATE[22P02] invalid input syntax for type uuid`. A kivétel korábban kiszökött a
     * motorból, és a futás ÖRÖKKÉ esedékes maradt (lásd a `tick()` és a `backOff()`
     * indoklását). A guard eredetileg CSAK a `sendEmail()`-ben volt meg — a `tag()`, a
     * `hasTag()` és az `inSegment()` nyersen küldte a configból jött sztringet.
     *
     * A leggyakoribb éles eset nem is elgépelés: a séma ÜRES `goal_config`-ja mellett a
     * `has_tag` cél `''`-t ad, ami szintén `22P02`.
     */
    private static function uuidOrNull(string $value): ?string
    {
        return Str::isUuid(trim($value)) ? trim($value) : null;
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
            /*
             | v1.1 (S8) — a merge-tagek és a feltételes blokkok ADATA, ugyanazzal a szabállyal,
             | mint a kampánynál (`MergeVariables`): kontakt, workspace, a sablonban hivatkozott
             | szegmensek tagsága és egyéni mezők.
             */
            $contact = $run->contact_id === null ? null : Contact::query()->find($run->contact_id);
            $variables = app(MergeVariables::class);
            $variables->prepare([(string) $template->html], $contact === null ? [] : [(int) $contact->getKey()]);

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
                'render_variables' => $variables->forContact($contact, app(WorkspaceContext::class)->workspace()),
                'options' => ['studio_origin' => 'journey'],
                /*
                 | AZ IDEMPOTENCIA-KULCS a futás, a lépés ÉS AZ ISMÉTLÉS hármasa.
                 |
                 | Az `iteration` nélkül a kulcs egy CIKLIKUS journey-ben minden körben
                 | ugyanaz volt, a `SendMessage` pedig az `messages.idempotency_key`-re
                 | nézve ÖRÖKRE replayel. Mérve egy `send_email → wait → vissza` cikluson:
                 | a `journey_run_steps` ÖT sort írt (`iteration=1..5`), mind `outcome='sent'`,
                 | mind UGYANAZZAL a `message_uuid`-dal — miközben `messages = 1` és a
                 | FakeDriver 1 küldést látott. Vagyis egy heti emlékeztető EGYETLEN levelet
                 | küldött, és a lépés-napló ötször azt hazudta, hogy elküldte. Ez rosszabb,
                 | mint a nem-küldés, mert elrejti.
                 |
                 | A `journey_run_steps` egyedi kulcsa a dupla VÉGREHAJTÁS ellen véd; ez a
                 | második réteg a dupla KÜLDÉS ellen — a kettő nem ugyanaz.
                 */
                'idempotency_key' => 'journey:'.$run->uuid.':'.$step->uuid.':'.$claim->iteration,
            ]);

            /*
             | IDEMPOTENCY_KEY_REUSED = visszajátszás (ugyanaz, mint a kampánynál, D53): a
             | kulcs belső (futás + lépés + iteráció), tehát eltérő lenyomat csak a renderelési
             | adat elcsúszásából jöhet. Az ELŐZŐ kísérlet üzenete dönt.
             */
            $allowed = $result->decision->allowed;
            $messageUuid = $result->message?->uuid;

            if ($result->decision->reason === GateReason::IdempotencyKeyReused->value) {
                $existing = Message::query()->where('idempotency_key', 'journey:'.$run->uuid.':'.$step->uuid.':'.$claim->iteration)->first();
                $allowed = $existing !== null && $existing->status !== 'rejected';
                $messageUuid = $existing?->uuid;
            }

            $claim->forceFill([
                'message_uuid' => $messageUuid,
                // A KAPU DÖNTÉSE: a riportnak meg kell tudnia mondani, miért nem kapta meg
                // valaki a journey-levelet — a `skipped` nem hiba, hanem a kapu munkája.
                'outcome' => $allowed ? 'sent' : 'skipped',
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
        return $this->applyTag($run, $step, (string) ($config['tag'] ?? ''), $step->stepType() === StepType::AddTag);
    }

    private function applyTag(JourneyRun $run, JourneyStep $step, string $tagUuid, bool $add): bool
    {
        $uuid = self::uuidOrNull($tagUuid);

        // A NEM FELOLDHATÓ HIVATKOZÁS HANGOS HIBA, nem csendes átlépés.
        //
        // Korábban a `null` tag egyszerűen továbbléptette a futást: a marketinges
        // beállított egy címkézést, ami soha nem történt meg, és semmi nem jelezte. A
        // `config_invalid` a felületen megnevezhető ok.
        if ($uuid === null) {
            $this->finish($run, 'failed', 'config_invalid');

            return false;
        }

        $contact = $run->contact;
        $tag = ContactTag::query()->where('uuid', $uuid)->first();

        if ($tag === null) {
            $this->finish($run, 'failed', 'config_invalid');

            return false;
        }

        if ($contact !== null) {
            $add
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

        /*
         | 2. A JOURNEY már nem aktív (a tenant leállította vagy archiválta).
         |
         | A `null` ÉS a „nem aktív" NEM ugyanaz, és ez a különbség mérve egy futást ölt meg.
         |
         | Ha a workspace-kontextus közben elveszik (egy hívási lánc `forget()`-je), a
         | `$run->journey` a `WorkspaceScope` fail-closed ágán `null` lesz — a journey
         | LÉTEZIK és AKTÍV, csak nem látjuk. Ez korábban `journey_archived`-ként
         | VÉGLEGESEN lezárta a futást: mérve egy háromlevelesnél az első levél után
         | `status=exited`, és a 2–3. levél SOHA nem ment ki.
         |
         | A fail-closed azt jelenti, hogy NE CSELEKEDJ — nem azt, hogy ÖLJ MEG. A hiányzó
         | journey ezért kivétel, amit a `tick()` `backOff()`-ra fordít: a futás késleltetve
         | visszatér, és ha a hiba tartós, `MAX_FAILURES` után hangosan zárul.
         */
        $journey = $run->journey;

        if ($journey === null) {
            throw new RuntimeException(sprintf(
                'A journey nem olvasható a futáshoz (%s) — valószínűleg elveszett a '
                .'workspace-kontextus. A futást NEM zárjuk le: a fail-closed azt jelenti, '
                .'hogy nem cselekszünk, nem azt, hogy megöljük.',
                (string) $run->uuid
            ));
        }

        /*
         | A SZÜNETELTETETT journey TART, nem léptet ki (v1.1).
         |
         | Korábban minden nem-aktív állapot `journey_archived`-dal lezárta a futást — a
         | „szünet" így valójában mindenkit kidobott, miközben a felület azt ígérte, hogy a
         | bent lévők ott maradnak, ahol vannak. A szünet alatt a futás nem lép (a tick itt
         | megáll), a folytatáskor onnan megy tovább.
         */
        if ($journey->isPaused()) {
            return true;
        }

        if (! $journey->isActive()) {
            $this->finish($run, 'exited', 'journey_archived');

            return true;
        }

        /*
         | 2. A HOZZÁJÁRULÁS VISSZAVONÁSA — suppression-sor nélkül is.
         |
         | Ez a kapu hiányzott. Mérve: `ConsentRecorder::revoke()` után (a consent nem
         | használható, suppression-sor NINCS) a futás továbbment, `chain_end`-del
         | `completed` lett, és A CÍMKE FELKERÜLT a kontaktra. A levél a ConsentGate-en
         | elakadt volna, de a docblock nem csak a küldésről beszél: „a címkézés és a
         | szegmens-mozgatás is profilalkotás, amit egy leiratkozott embernél nem
         | folytatunk". Visszavont hozzájárulás mellett a profilalkotás folytatódott.
         |
         | A preference centeren keresztüli leiratkozás suppressiont IS ír, tehát a 70.
         | kritérium fő útja állt — a rés a `revoke()` suppression nélküli hívási útjain volt.
         */
        if ($this->consentRevoked($run)) {
            $this->finish($run, 'exited', 'consent_revoked');

            return true;
        }

        // 3. ENTITLEMENT (70. kritérium): a `journeys` funkció a csomag része-e.
        //
        // Futás KÖZBEN is ellenőrizzük, nem csak belépéskor: egy lefokozott csomag nem
        // futtathat tovább automatizálást hetekig. A levél, ami már elindult, nem hívható
        // vissza — a következő nem mehet ki.
        // A JOGOSULTSÁG TICKENKÉNT EGYSZER kérdeződik meg workspace-enként.
        //
        // Ez lépésenként hívódott: a `FakeVentPayClient`-nél ingyen, de éles ventPay-jel
        // futásonként-lépésenként EGY kimenő HTTP-kérés. Egy tízezres journey tickje
        // tízezer hívás lett volna ugyanarra a kérdésre, aminek a válasza egy tick alatt
        // nem változik.
        if (! $this->entitled((int) $run->workspace_id)) {
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

    /**
     * A JOGOSULTSÁG — tickenként, workspace-enként gyorsítótárazva.
     *
     * A gyorsítótár szándékosan a PÉLDÁNYON él, nem a cache-ben: egy tick erejéig szól, és
     * a következő parancsfutás friss választ kér. Egy percnél hosszabb érvényességet nem
     * akarunk adni neki — a lefokozott csomag nem futtathat tovább automatizálást.
     *
     * @var array<int, bool>
     */
    private array $entitlements = [];

    private function entitled(int $workspaceId): bool
    {
        // A `EntitlementChecker`-en: a SUB-ACCOUNT a PARENT csomagját használja (C1 · 76.).
        return $this->entitlements[$workspaceId]
            ??= $this->checker->allows(Workspace::query()->findOrFail($workspaceId), Feature::Journeys);
    }

    /**
     * VISSZAVONT-E a hozzájárulás?
     *
     * ## A `pending` NEM `revoked` — és ez a különbség a lényeg
     *
     * A „nincs megerősített consent" kérdés ITT HELYTELEN válasz lenne, és a packet saját
     * tesztje mutatja meg, miért: egy űrlap-beküldés (NEM hamisítható forrás) `pending`
     * consenttel LEGITIMEN beléptet, és a journey a megerősítésre „vár" — a marketing
     * levelet közben a PreSendGate 3. kapuja tartja vissza, külön logika nélkül. Ha ez a
     * kapu a `pending`-et is kilépésnek vennéaz, a double opt-in flow-t törnénk el: aki
     * három nap múlva megerősít, addig kiesett volna a journey-ből.
     *
     * Amit ez a kapu néz, az a VISSZAVONÁS: volt hozzájárulás, és az érintett elvette.
     * Mérve: `ConsentRecorder::revoke()` után (suppression-sor NÉLKÜL) a futás továbbment,
     * és A CÍMKE FELKERÜLT a kontaktra — profilalkotás visszavont jogalap mellett, hetekig.
     * A levél a kapun elakadt volna, de a `shouldExit()` docblockja nem csak a küldésről
     * beszél: „a címkézés és a szegmens-mozgatás is profilalkotás".
     */
    private function consentRevoked(JourneyRun $run): bool
    {
        return Consent::query()
            ->where('email', (string) $run->email)
            ->where('channel', Channel::Journey->consentScope())
            ->whereNotNull('revoked_at')
            ->exists();
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
