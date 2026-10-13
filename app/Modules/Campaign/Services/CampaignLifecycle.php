<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Jobs\StartCampaignJob;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\TemplateCompliance;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * A KAMPÁNY ÁLLAPOTGÉPE (62. kritérium) — az egyetlen hely, ahol az állapot változik.
 *
 * Miért nem a modellben, `forceFill`-ekkel szétszórva: az átmenetek FELTÉTELEI nem csak
 * állapotból állnak. Az ütemezéshez kell jövőbeli időpont, az indításhoz kell sablon és
 * célzás, a folytatáshoz kell, hogy legyen még kinek küldeni. Ha ez a hívási helyeken
 * lenne, minden új belépési pont (API, felület, ütemező, journey) újra megírná — és
 * valamelyik kihagyna egy feltételt.
 *
 * A modell `updating` hookja ezt KIEGÉSZÍTI: a célzás és a sablon csak szerkeszthető
 * állapotban változhat. A kettő együtt zárja a kört.
 */
final class CampaignLifecycle
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly CampaignBatchDispatcher $batches,
    ) {}

    /** ÜTEMEZÉS — jövőbeli időpontra, a tenant időzónájában értelmezve. */
    public function schedule(Campaign $campaign, string $localTime, string $timezone): Campaign
    {
        $this->assertReady($campaign);

        // AZ IDŐZÓNA ELLENŐRZÉSE ITT: egy elgépelt zóna (`Europe/Budapesst`) különben
        // kivételt dobna a `Carbon`-ban, és a felhasználó egy nyers hibát látna.
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("Ismeretlen időzóna: {$timezone}.");
        }

        $at = Carbon::parse($localTime, $timezone);

        // A JÖVŐBELI IDŐPONT követelménye nem formalitás: a múltra ütemezett kampányt az
        // ütemező AZONNAL esedékesnek látná, és a felhasználó azt hinné, hogy még van
        // ideje szerkeszteni.
        if ($at->isPast()) {
            throw new InvalidArgumentException(
                'Az ütemezett időpont a jövőben kell legyen. Azonnali küldéshez indítsd el a kampányt.'
            );
        }

        // A `scheduled_at` UTC-ben (a `timestamptz` amúgy is azt tesz), a `timezone` a
        // MEGJELENÍTÉSHEZ és a nyári időszámítás miatti újraszámításhoz.
        return $this->change($campaign, CampaignStatus::Scheduled, static function (Campaign $locked) use ($at, $timezone): void {
            $locked->forceFill([
                'scheduled_at' => $at->utc(),
                'timezone' => $timezone,
            ]);
        }, allowSame: true);
    }

    /** VISSZAVÉTEL vázlatba — a felhasználó még szerkeszt. */
    public function unschedule(Campaign $campaign): Campaign
    {
        return $this->change($campaign, CampaignStatus::Draft, static function (Campaign $locked): void {
            $locked->forceFill(['scheduled_at' => null]);
        });
    }

    /**
     * INDÍTÁS. A címzett-halmaz materializálása a `StartCampaign` action dolga.
     *
     * A `sending` ÁLLAPOTOT FELTÉTELES UPDATE IGÉNYLI, nem egy beolvasás-utáni mentés.
     *
     * Az `transition()` korábban csendben visszatért, ha az állapot MÁR a cél volt — így a
     * `StartCampaign` egy már `sending` kampányon újra végigfutott, és MÁSODIK batchet
     * indított ugyanazokra a `pending` sorokra. Mérve két különböző `batch_id`. Dupla levél
     * nem lett belőle (az idempotencia-kulcs fogja), de a számlálók duplán nőttek: mérve
     * `sent_count = 2` `recipient_count = 1` mellett — vagyis a kampány-riport HAZUDOTT.
     *
     * Két ütemező-futás vagy egy API + ütemező verseny pontosan ide vezet, ezért nem elég
     * az állapotot ellenőrizni: IGÉNYELNI kell. Az `UPDATE … WHERE status IN (…)` az
     * adatbázisban atomikus, tehát a két versenyző közül pontosan egy kapja meg.
     */
    public function start(Campaign $campaign): Campaign
    {
        $this->assertReady($campaign);

        $from = $campaign->statusEnum();

        if (! $from->canTransitionTo(CampaignStatus::Sending)) {
            $this->refuse($from, CampaignStatus::Sending);
        }

        $claimed = Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', $from->value)
            ->update([
                'status' => CampaignStatus::Sending->value,
                'started_at' => $campaign->started_at ?? now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new RuntimeException(
                'A kampányt közben már elindította valaki más (vagy megváltozott az állapota). '
                .'Egy második indítás dupla számlálókat és második köteget adna.'
            );
        }

        // EXPLICIT AUDIT (review S3): a feltételes UPDATE megkerüli a modell Auditable hookját,
        // pedig az indítás a legsúlyosabb kampány-művelet. Felületről a bejelentkezett
        // felhasználó, ütemezőből / háttérből `system` az aktor.
        app(AuditLogger::class)->log('campaign.started', $campaign, context: [
            'actor' => Auth::check() ? 'user' : 'system',
            'from' => $from->value,
        ]);

        return $campaign->fresh();
    }

    /**
     * SZÜNETELTETÉS — a batchet is TÖRLI.
     *
     * A törlés nélkül a sorban álló kötegek lefutottak, és a `SendCampaignChunk` korai
     * `return`-jével ELFOGYTAK (a job törlődik a sorból). A folytatás után azok a címzettek
     * SOHA nem kaptak volna levelet: a kampány örökre „küldés folyamatban" maradt, nulla
     * küldéssel. Mérve pontosan ez történt.
     */
    public function pause(Campaign $campaign): Campaign
    {
        $paused = $this->change($campaign, CampaignStatus::Paused);

        // A köteg törlése a COMMIT UTÁN (a friss `batch_id`-vel): a futó chunk-jobok ekkor már a
        // `paused` állapotot látják.
        $this->cancelBatch($paused);

        return $paused;
    }

    /**
     * FOLYTATÁS. Ha nincs már kinek küldeni, a kampány BEFEJEZETT — nem `sending`.
     *
     * Enélkül egy szüneteltetett, majd folytatott kampány örökre `sending` maradna, és a
     * felületen „küldés folyamatban"-t mutatna anélkül, hogy bármi történne.
     */
    public function resume(Campaign $campaign): Campaign
    {
        // v1.1 (review RB1, SN2) · NEM TELJESEN MATERIALIZÁLT kampány (a háttér-felírás elbukott
        // vagy közben szüneteltették): a folytatás ÚJRA materializál. A `recipients()->exists()`
        // nem elég: egy félbehalt felírás első kötegei már ott vannak, és a folytatás a CSONKA
        // közönségre küldött volna. A `materialized_at` csak a TELJES felírás után íródik.
        if ($campaign->materialized_at === null) {
            $resumed = $this->change($campaign, CampaignStatus::Sending);

            StartCampaignJob::dispatch((string) $resumed->uuid, (int) $resumed->workspace_id);

            // FRISS példány: szinkron sornál a job már le is futott (akár be is fejezte a kampányt).
            return $resumed->fresh() ?? $resumed;
        }

        if ($campaign->recipients()->where('status', 'pending')->doesntExist()) {
            return $this->complete($campaign);
        }

        // Az állapot a zár alatt vált, a kötegek a COMMIT UTÁN indulnak: tranzakción belül
        // indított chunk-job a még nem látható `sending` helyett `paused`-ot olvasna, és a
        // köteget csendben elfogyasztaná.
        $campaign = $this->change($campaign, CampaignStatus::Sending);

        // A FOLYTATÁS ÚJ KÖTEGEKET INDÍT. Ez korábban kimaradt: a `resume()` átírta az
        // állapotot `sending`-re, a `batch_id` VÁLTOZATLAN maradt, és egyetlen job sem
        // került a sorba. Mérve: pause → 3 `pending`; resume → `sending`, 3 `pending`,
        // nulla job. A teszt csak a flaget nézte (`status === 'sending'`), ezért nem
        // látszott — egy flag nem viselkedés.
        //
        // ÚJ KÖTEGEK a `pending` sorokra. A `null` azt jelenti, hogy közben elfogytak —
        // akkor a kampány BEFEJEZETT, nem „küldés folyamatban".
        if ($this->batches->dispatch($campaign->fresh()) === null) {
            return $this->complete($campaign->fresh());
        }

        return $campaign->fresh();
    }

    /**
     * A FUTÓ KÖTEG TÖRLÉSE.
     *
     * A `Bus::findBatch` `null`-t ad, ha a batch már lejárt vagy törölve lett — ez nem hiba,
     * hanem a normális eset egy régen befejezett kampánynál.
     */
    private function cancelBatch(Campaign $campaign): void
    {
        // FRISS érték az adatbázisból (review BN1): a háttér-materializálás a példány betöltése
        // után indíthatott köteget — az elavult `batch_id` azt nem állítaná le.
        $batchId = (string) (Campaign::query()->whereKey($campaign->getKey())->value('batch_id') ?? '');

        if ($batchId === '') {
            return;
        }

        Bus::findBatch($batchId)?->cancel();
    }

    public function complete(Campaign $campaign): Campaign
    {
        return $this->change($campaign, CampaignStatus::Sent, static function (Campaign $locked): void {
            $locked->forceFill(['finished_at' => now()]);
        });
    }

    public function cancel(Campaign $campaign): Campaign
    {
        $canceled = $this->change($campaign, CampaignStatus::Canceled, static function (Campaign $locked): void {
            $locked->forceFill(['finished_at' => now()]);
        });

        $campaign = $canceled;

        $this->cancelBatch($campaign);

        // A MÉG NEM KIKÜLDÖTT címzettek `skipped` állapotba kerülnek, nem törlődnek: a
        // riportnak meg kell tudnia mondani, hogy hányan NEM kapták meg, és miért.
        $campaign->recipients()->where('status', 'pending')->update([
            'status' => 'skipped',
            'skip_reason' => 'campaign_canceled',
            'updated_at' => now(),
        ]);

        return $campaign->fresh();
    }

    /**
     * ÁLLAPOTVÁLTÁS SOR-ZÁRRAL, összehasonlítás-és-csere szemantikával (review S2).
     *
     * A korábbi „beolvasás → átírás → mentés" egy ELAVULT példányból dolgozott: egy közben
     * `sending`-re váltott kampányt egy késve érkező `unschedule` vagy `cancel` felülírt. Most a
     * sor zárolva újra beolvasódik, és ha az állapota NEM az, amit a hívó látott (a példány
     * eredeti állapota), az átmenet HANGOSAN elutasul — a felület „töltsd újra" hibát mutat.
     *
     * Ha a sor már a CÉLÁLLAPOTBAN van (két köteg egyszerre zárja a kampányt, dupla kattintás),
     * a hívás idempotens no-op — kivéve, ha `allowSame` (az újraütemezés új időpontot ír).
     *
     * @param  (callable(Campaign): void)|null  $mutate  a zárolt példány további mezői (mentés előtt)
     */
    private function change(Campaign $campaign, CampaignStatus $target, ?callable $mutate = null, bool $allowSame = false): Campaign
    {
        $expected = (string) $campaign->getOriginal('status', $campaign->status);

        return DB::transaction(function () use ($campaign, $target, $mutate, $allowSame, $expected): Campaign {
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $locked->status === $target->value && ! $allowSame) {
                return $locked;
            }

            if ((string) $locked->status !== $expected) {
                throw new RuntimeException(sprintf(
                    'A kampány állapota közben megváltozott (%s → %s). Töltsd újra az oldalt, és próbáld újra.',
                    $expected,
                    (string) $locked->status,
                ));
            }

            $this->transition($locked, $target);

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->save();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Az ÁTMENET ellenőrzése és végrehajtása.
     *
     * A tiltott átmenet KIVÉTEL, nem csendes no-op: egy „nem történt semmi" válasz után a
     * hívó azt hinné, hogy sikerült, és a kampány örökre a régi állapotban maradna.
     */
    private function transition(Campaign $campaign, CampaignStatus $target): void
    {
        $current = $campaign->statusEnum();

        if ($current === $target) {
            return;
        }

        if (! $current->canTransitionTo($target)) {
            $this->refuse($current, $target);
        }

        $campaign->status = $target->value;
    }

    private function refuse(CampaignStatus $current, CampaignStatus $target): never
    {
        throw new RuntimeException(sprintf(
            'Érvénytelen kampány-átmenet: %s → %s. Engedélyezett innen: %s.',
            $current->value,
            $target->value,
            implode(', ', CampaignStatus::transitions()[$current->value] ?: ['(nincs — végállapot)']),
        ));
    }

    /**
     * KÉSZ-e a kampány küldésre?
     *
     * Ez a lista szándékosan ITT van, nem a hívóknál: az „elfelejtett feltétel" a
     * legdrágább hiba egy kampánynál — egy tárgy nélküli levél a teljes közönségnek megy ki.
     */
    private function assertReady(Campaign $campaign): void
    {
        $missing = [];

        if ($campaign->template_id === null) {
            $missing[] = 'sablon';
        }

        if (trim((string) $campaign->subject) === '') {
            $missing[] = 'tárgy';
        }

        if (trim((string) $campaign->from_email) === '') {
            $missing[] = 'feladó';
        }

        if ((array) $campaign->target_lists === [] && (array) $campaign->target_segments === []) {
            $missing[] = 'célzás (lista vagy szegmens)';
        }

        // v1.1 (S1) · a lábléc HELYŐRZŐ postai címe nem postai cím: a marketing levél
        // így nem indulhat el (a sablonnak és a változatok sablonjainak egyaránt).
        $templates = Template::query()
            ->whereKey(array_filter([$campaign->template_id, ...$campaign->variants()->pluck('template_id')->all()]))
            ->get();

        foreach ($templates as $template) {
            if ($template->marketing && app(TemplateCompliance::class)->hasPlaceholderAddress((array) $template->blocks)) {
                $missing[] = 'valódi postai cím a láblécben ('.$template->name.')';
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'A kampány még nem indítható, hiányzik: '.implode(', ', $missing).'.'
            );
        }

        // A VARIÁNSOK SÚLYA 100% — a maradékot NEM osztjuk szét csendben.
        //
        // Egy 60/30-as beállításnál a hiányzó 10% vagy elveszne (tíz százalék a közönség
        // nem kap levelet), vagy valahova kerülne — és a felhasználó egyik esetben sem
        // tudná, melyik történt.
        // EGY VARIÁNS: HANGOS elutasítás, nem csendes figyelmen kívül hagyás.
        //
        // A `hasVariants()` csak >1 variánsnál igaz, és a `VariantAssigner::plan()` is üres
        // tömböt ad <2-nél. Pontosan EGY variáns esetén tehát a variáns sablonja, tárgya és
        // súlya CSENDBEN elveszett, és a súlyellenőrzés sem futott: a felhasználó beállított
        // egy A/B tesztet, ami nem történt meg.
        $variantCount = $campaign->variants()->count();

        if ($variantCount === 1) {
            throw new InvalidArgumentException(
                'Egyetlen A/B variáns nem értelmezhető: vagy legalább kettő kell, vagy '
                .'egy se (a kampány sablonja és tárgya megy ki).'
            );
        }

        if ($campaign->hasVariants()) {
            $total = (int) $campaign->variants()->sum('weight');

            if ($total !== 100) {
                throw new InvalidArgumentException(sprintf(
                    'Az A/B variánsok súlyának összege 100 kell legyen (most %d).',
                    $total
                ));
            }
        }

        // A CÉLZÁS ne legyen ÜRES. A becslés friss: a küldés előtti pillanatban kérdezzük.
        //
        // KERETEZVE (review SN3): az indítás webkérésben is fut, és egy drága szegmens feloldása
        // nem foglalhat le egy FPM-workert percekig. Keret-túllépésnél a kérdés nyitva marad —
        // a háttér-materializálás üres halmaznál a kampányt befejezettre zárja, köteg nélkül.
        try {
            $estimate = app(QueryBudget::class)->run(fn (): int => $this->audience->estimate($campaign));
        } catch (QueryTooExpensive) {
            $estimate = null;
        }

        if ($estimate === 0) {
            throw new InvalidArgumentException(
                'A célzás egyetlen elérhető címzettet sem ad. Ellenőrizd a listákat, a '
                .'szegmenseket és a kizárásokat.'
            );
        }
    }
}
