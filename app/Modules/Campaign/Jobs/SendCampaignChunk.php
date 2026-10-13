<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Events\CampaignProgressed;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\MergeVariables;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\PlainTextWriter;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EGY KÖTEG kampány-levél kiküldése (65., 66. kritérium).
 *
 * ## A KAMPÁNY UGYANAZON A MOTORON MEGY
 *
 * A `SendMessage` action hívása nem kényelmi döntés, hanem a `CLAUDE.md` első szabálya: „a
 * kampány ugyanazon a küldőmotoron megy, mint az API-hívás". Ez azt jelenti, hogy a kampány
 * levele MIND A TÍZ PreSendGate-kapun átmegy — domain-hitelesítés, suppression, consent,
 * kvóta, rate-limit, címellenőrzés, leiratkozó fejléc. Egy külön „kampány-küldő" út
 * megkerülné őket, és pontosan ez a golden rule 2 tiltása.
 *
 * ## A CSATORNA `campaign`, nem `marketing`
 *
 * Így a sor-prioritás (`SendQueue::forChannel`) a legalacsonyabbra teszi, és a
 * `Channel::queuePriority()` szerint a tranzakciós MEGELŐZI. A consent-követelmény ettől
 * nem lazul: a `Channel::requiresConsent()` a `campaign`-re is igaz.
 *
 * ## A KÖTEG NEM SZAKÍTJA MEG A TÖBBIT
 *
 * Egy címzett hibája (elutasított cím, kapu-blokk) a SORÁT jelöli meg, nem dobja el a
 * köteget. Egy `Throwable` a jobból a batch `allowFailures()` mellett is azt jelentené,
 * hogy 250 címzett közül egy hibás miatt 249 nem kap levelet.
 */
final class SendCampaignChunk implements ShouldQueue
{
    use Batchable, Queueable;

    private const OUTCOME_SENT = 'sent';

    private const OUTCOME_FAILED = 'failed';

    private const OUTCOME_RETRY = 'retry';

    /**
     * AZ ÁTMENETI elutasítások — ezek nem zárják ki a címzettet a kampányból.
     *
     * A `RATE_LIMITED` a másodperces/percenkénti limit, a `GATE_ERROR` pedig egy kapu
     * hibája (pl. a kvóta-szolgáltatás nem érhető el). Egyik sem a címzettről szól.
     *
     * @var list<string>
     */
    private const TRANSIENT_REASONS = [GateReason::RateLimited->value, GateReason::GateError->value];

    /** A visszaengedés késleltetése — a limit-ablaknál hosszabb, hogy ne pörögjön. */
    private const RETRY_DELAY = 60;

    /**
     * Egy címzett ÁTMENETI elutasításainak felső száma (S4). Utána a sor `failed`
     * (`transient_exhausted:<ok>`) — egy tartósan kimerült kvóta ne pörgesse a köteget
     * végtelenül.
     */
    public const MAX_TRANSIENT_ATTEMPTS = 10;

    /**
     * A JOB próbálkozásainak száma — a worker `tries` beállítása FÖLÖTT (S4).
     *
     * A Horizon supervisorok `tries => 1`-gyel futnak: a `release()` után a második
     * felvételkor a job `MaxAttemptsExceededException`-nel elbukna, és az átmeneti
     * elutasítás utáni újrapróbálás élesben SOHA nem történne meg. A job saját `$tries`-a
     * elsőbbséget élvez; a címzettenkénti plafon fölé +2 (az első futás és egy tartalék).
     */
    public int $tries = self::MAX_TRANSIENT_ATTEMPTS + 2;

    /** @param list<int> $recipientIds */
    public function __construct(
        private readonly string $campaignUuid,
        private readonly int $workspaceId,
        private readonly array $recipientIds,
    ) {
        $this->afterCommit();
    }

    public function handle(WorkspaceContext $context, SendMessage $send): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $campaign = Campaign::query()->with('template')->where('uuid', $this->campaignUuid)->first();

            if ($campaign === null) {
                return;
            }

            // A SZÜNETELTETÉS ÉS A VISSZAVONÁS ITT is számít: a batch törlése nem
            // azonnali, tehát egy már futó job még beeshet. A `pending` sorok érintetlenül
            // maradnak, tehát a folytatás pontosan onnan veszi fel.
            if (! in_array((string) $campaign->status, ['sending'], true)) {
                return;
            }

            if ($this->batch()?->cancelled() === true) {
                return;
            }

            $counts = ['sent_count' => 0, 'failed_count' => 0];
            $variantSent = [];
            $release = false;

            $recipients = $this->recipients($campaign);

            // v1.1 — a MERGE-VÁLTOZÓK (kontakt, workspace, a sablonban hivatkozott
            // szegmensek tagsága) kötegenként EGYSZER készülnek elő.
            $variables = app(MergeVariables::class);
            $variables->prepare(
                $recipients->map(fn (CampaignRecipient $r) => (string) (($r->variant?->template ?? $campaign->template)?->html ?? ''))
                    ->unique()->values()->all(),
                $recipients->pluck('contact_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            );

            foreach ($recipients as $recipient) {
                $outcome = $this->sendTo($campaign, $recipient, $send, $variables->forContact($recipient->contact, $workspace));

                if ($outcome === self::OUTCOME_RETRY) {
                    // ÁTMENETI ELUTASÍTÁS: a sor `pending` marad, és a job VISSZAMEGY a
                    // sorba. Lásd a `sendTo()` indoklását.
                    $release = true;

                    continue;
                }

                $counts[$outcome === self::OUTCOME_SENT ? 'sent_count' : 'failed_count']++;

                if ($outcome === self::OUTCOME_SENT && $recipient->campaign_variant_id !== null) {
                    $variantSent[(int) $recipient->campaign_variant_id] =
                        ($variantSent[(int) $recipient->campaign_variant_id] ?? 0) + 1;
                }
            }

            // A SZÁMLÁLÓK KÖTEGENKÉNT, nem címzettenként.
            //
            // A soronkénti `increment()` 250 UPDATE-et jelentett UGYANARRA a kampány-sorra
            // egy kötegben — sorszintű lock-verseny a párhuzamosan futó kötegek között,
            // ahol minden köteg a másikra vár. Egy összesített UPDATE ugyanazt az
            // eredményt adja egy zárral.
            $this->bumpCounters($campaign, $counts, $variantSent);

            $this->finishOrReport($campaign);

            if ($release) {
                // A VISSZAENGEDÉS a LEGVÉGÉN: előbb a már elküldött címzettek számlálói és
                // a progressz rögzülnek, csak utána megy vissza a job. Fordított sorrendben
                // az újrafutás egy be nem írt kötegről indulna újra.
                $this->release(self::RETRY_DELAY);
            }
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /** @var array<int, array{html: string, text: string}> sablon-id → a kampány-preheaderrel fordított törzs */
    private array $bodies = [];

    /**
     * v1.1 · A TÁRGY merge-tagjei címzettenként (`{{ contact.first_name || … }}`).
     *
     * Ugyanaz a renderelő és ugyanazok a változók, mint a törzsé, de ESCAPE NÉLKÜL (a tárgy
     * nem HTML), és a sortörés szóközzé válik — egy változó-értékből nem lehet fejléc-
     * injekció. Merge-tag nélküli tárgyhoz nem nyúlunk.
     *
     * @param  array<string, mixed>  $variables
     */
    private function subjectFor(string $subject, array $variables): string
    {
        if (str_contains($subject, '{{')) {
            $subject = app(TemplateRenderer::class)->render($subject, $variables, escape: false);
        }

        // MINDEN vezérlőkarakter szóköz (review RS2/N5) — nem csak a CR/LF: a NUL, a BEL és
        // a többi C0/DEL egy kontakt-mezőből a fejlécbe nem kerülhet. A `messages.subject`
        // `varchar(255)`: a tárgy 255 karakterre vágódik (a hosszú név nem 500-as hiba).
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));

        return mb_substr((string) preg_replace('/ {2,}/', ' ', $clean), 0, 255);
    }

    /**
     * v1.1 · A KAMPÁNY PREHEADERE felülírja a sablonét (a checklist „Tárgy és preheader"
     * szakasza). A törzs sablononként EGYSZER fordul újra a kötegben — a forrás továbbra is
     * a sablon blokk-fája, tehát a lábléc és a leiratkozó link érintetlen.
     *
     * @return array{html: string, text: string}
     */
    private function bodyFor(Campaign $campaign, Template $template): array
    {
        $preheader = trim((string) $campaign->preheader);

        if ($preheader === '' || $preheader === trim((string) $template->preheader)) {
            return ['html' => (string) $template->html, 'text' => (string) $template->text];
        }

        return $this->bodies[(int) $template->getKey()] ??= (function () use ($template, $preheader): array {
            $blocks = (array) $template->blocks;
            $styles = is_array($template->styles) ? $template->styles : null;

            return [
                'html' => app(MjmlCompiler::class)->compile(app(MjmlWriter::class)->write($blocks, $preheader, $styles)),
                'text' => app(PlainTextWriter::class)->write($blocks, $preheader),
            ];
        })();
    }

    /** @return Collection<int, CampaignRecipient> */
    private function recipients(Campaign $campaign): Collection
    {
        return $campaign->recipients()
            // AZ EAGER LOAD nem teljesítmény-finomítás: a projekt tiltja a lazy loadingot
            // (`preventLazyLoading`), tehát a `$recipient->variant` KIVÉTELT dob. És jó
            // okkal: 250 címzettnél a soronkénti variáns- és sablon-lekérdezés 500 extra
            // körutazás lenne EGY kötegben.
            ->with(['variant.template', 'contact'])
            ->whereIn('id', $this->recipientIds)
            // CSAK a `pending` sorok: egy újrapróbált job nem küld másodszor. Ez az
            // idempotencia gyakorlati alapja — a `sent` sort nem nyúlja meg.
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();
    }

    /**
     * EGY CÍMZETT — és a kimenet HÁROM állapotot tud, nem kettőt.
     *
     * ## Miért nem `skipped` a rate-limit
     *
     * A kapu elutasításai NEM egyneműek. A „nincs consent" és a „suppression-listán van"
     * VÉGLEGES: az a címzett ebből a kampányból nem kap levelet, és a riportnak ezt kell
     * mondania. A `RATE_LIMITED` és a `GATE_ERROR` viszont ÁTMENETI — a kvóta-szolgáltatás
     * egy pillanatra nem érhető el, vagy a másodperces limit betelt.
     *
     * Ez korábban összemosódott: MINDEN elutasítás `skipped` lett, a `pending` sor eltűnt,
     * a kampány pedig `sent`-re zárt. Vagyis egy rate-limit-csúcs alatt futó köteg
     * címzettjei VÉGLEGESEN kimaradtak, a felület meg „elküldve" állapotot mutatott — a
     * legrosszabb kombináció: adatvesztés hangos hiba nélkül.
     *
     * Az átmeneti esetben a sor `pending` marad, és a job `release()`-szel visszamegy a
     * sorba. A `pending` szűrő miatt a már kiküldött címzettek nem kapnak másodszor.
     *
     * @return self::OUTCOME_* mi történt a címzettel
     */
    /** @param array<string, mixed> $renderVariables */
    private function sendTo(Campaign $campaign, CampaignRecipient $recipient, SendMessage $send, array $renderVariables = []): string
    {
        $variant = $recipient->variant;
        $template = $variant?->template ?? $campaign->template;

        if ($template === null) {
            $recipient->forceFill(['status' => 'failed', 'skip_reason' => 'no_template'])->save();

            return self::OUTCOME_FAILED;
        }

        /*
         | AZ IDEMPOTENCIA-KULCS a kampány és a címzett párosa — ÁTMENETI elutasítás után
         | (S11) új kísérlet-sorszámmal. A `RATE_LIMITED` / `GATE_ERROR` levél a kapun nem
         | ment át, tehát ki sem ment; ugyanazzal a kulccsal viszont a `SendMessage` mindig a
         | régi elutasítást tükrözte vissza, és a címzett SOHA nem kapta meg a levelet. A
         | sorszám a sor `skip_reason`-jében (`retry:N`) él, amíg a címzett `pending`.
         */
        $attempt = str_starts_with((string) $recipient->skip_reason, 'retry:') ? (int) substr((string) $recipient->skip_reason, 6) : 0;
        $key = 'campaign:'.$campaign->uuid.':'.$recipient->email_hash.($attempt > 0 ? ':r'.$attempt : '');

        try {
            $result = $send([
                // A `campaign` csatorna: legalacsonyabb sor-prioritás, de a
                // consent-követelmény ugyanaz (`Channel::requiresConsent()`).
                'channel' => 'campaign',
                // v1.1 · FELADÓ-variáns (A/B): a variáns feladója, ha van — különben a kampányé.
                // A feladó-domain hitelesítését a PreSendGate 1. kapuja itt is ellenőrzi.
                'from_email' => (string) ($variant?->from_email ?: $campaign->from_email),
                'from_name' => $variant?->from_email ? ($variant->from_name ?: $campaign->from_name) : $campaign->from_name,
                'to' => [(string) $recipient->email],
                'subject' => $this->subjectFor((string) ($variant?->subject ?: $campaign->subject), $renderVariables),
                'html' => $this->bodyFor($campaign, $template)['html'],
                'text' => $this->bodyFor($campaign, $template)['text'],
                // v1.1 — a merge-tagek és a feltételes blokkok adata (titkosítva tárolódik,
                // a kézbesítés után törlődik; az esemény-naplóba nem kerül).
                'render_variables' => $renderVariables,
                // STUDIO-EREDET: a feltételes blokk és a `browser_url` csak ilyen törzsön él.
                'options' => ['studio_origin' => 'campaign'],
                // A KAMPÁNY-CÍMKE: a tag-szintű statisztika (A3) és a marketing analitika
                // (B4) ebből dolgozik — ugyanabból az esemény-forrásból, mint a
                // platform-napló.
                'tags' => array_values(array_filter([
                    'campaign:'.$campaign->uuid,
                    $variant === null ? null : 'variant:'.$variant->label,
                ])),
                // AZ IDEMPOTENCIA-KULCS a kampány és a címzett párosa: egy újrapróbált
                // köteg ugyanazt a kulcsot adja, tehát a levél nem megy ki kétszer — a
                // védelem így nem csak a `pending` szűrőn múlik.
                'idempotency_key' => $key,
            ]);

            if ($result->decision->allowed) {
                $recipient->forceFill([
                    'status' => 'sent',
                    'skip_reason' => null,
                    'message_uuid' => $result->message?->uuid,
                    'sent_at' => now(),
                ])->save();

                return self::OUTCOME_SENT;
            }

            $reason = (string) $result->decision->reason;

            /*
             | B1 · UGYANAZ a kulcs MÁS lenyomattal: ugyanannak a címzettnek UGYANAZ a kampány-
             | levele, csak a renderelési adat (név, szegmens-tagság) változott két kísérlet
             | között. Ez NEM másik levél — az ELŐZŐ kísérlet üzenete dönt: ha átment a
             | kapun, a címzett megkapta (`sent`); ha elutasult, annak az oka számít.
             */
            if ($reason === GateReason::IdempotencyKeyReused->value) {
                $existing = Message::query()->where('idempotency_key', $key)->first();

                if ($existing !== null && $existing->status !== 'rejected') {
                    $recipient->forceFill(['status' => 'sent', 'skip_reason' => null, 'message_uuid' => $existing->uuid, 'sent_at' => now()])->save();

                    return self::OUTCOME_SENT;
                }

                $reason = (string) ($existing?->reason ?? $reason);
            }

            if (in_array($reason, self::TRANSIENT_REASONS, true)) {
                if ($attempt + 1 >= self::MAX_TRANSIENT_ATTEMPTS) {
                    $recipient->forceFill([
                        'status' => 'failed',
                        'skip_reason' => 'transient_exhausted:'.$reason,
                        'message_uuid' => $result->message?->uuid,
                    ])->save();

                    return self::OUTCOME_FAILED;
                }

                // A SOR `pending` marad — a köteg újrafutása ÚJ kísérlet-kulccsal veszi fel.
                $recipient->forceFill(['skip_reason' => 'retry:'.($attempt + 1)])->save();

                return self::OUTCOME_RETRY;
            }

            $recipient->forceFill([
                'status' => 'skipped',
                // A KAPU DÖNTÉSE a `skip_reason`: a riportnak meg kell tudnia mondani, hogy
                // MIÉRT nem kapta meg valaki — „nincs consent" és „suppression-listán van"
                // két különböző beszélgetés az ügyféllel.
                'skip_reason' => $reason === '' ? 'rejected' : $reason,
                'message_uuid' => $result->message?->uuid,
                'sent_at' => null,
            ])->save();

            return self::OUTCOME_FAILED;
        } catch (Throwable $e) {
            // EGY CÍMZETT HIBÁJA a SORÁT jelöli meg, nem dobja el a köteget: 250 közül egy
            // hibás miatt 249 nem maradhat levél nélkül.
            //
            // NEM `report($e)`: a kivétel üzenete a CÍMET is tartalmazhatja (egy
            // adatbázis-hiba a nyers SQL-t viszi magával, egy validációs hiba a bemenetet),
            // és a hibafigyelő egy külső rendszer, ahol a napló-retenció nem a mi
            // szabályunk. Ami utazik: a kampány, a kivétel TÍPUSA és a cím LENYOMATA.
            Log::warning('Kampány-címzett hibára futott', [
                'campaign_uuid' => (string) $campaign->uuid,
                'recipient_hash' => (string) $recipient->email_hash,
                'exception' => $e::class,
            ]);

            $recipient->forceFill([
                'status' => 'failed',
                'skip_reason' => 'error:'.class_basename($e),
            ])->save();

            return self::OUTCOME_FAILED;
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, int>  $variantSent
     */
    private function bumpCounters(Campaign $campaign, array $counts, array $variantSent): void
    {
        $update = [];

        foreach ($counts as $column => $value) {
            if ($value > 0) {
                // A KIFEJEZÉS relatív (`+ n`), nem abszolút: két párhuzamos köteg
                // eredménye így összeadódik ahelyett, hogy egymást írnák felül. Az
                // oszlopnevek a `$counts` FIX kulcsai, a szám pedig `int` — nem jön kívülről.
                $update[$column] = DB::raw('"'.$column.'" + '.$value);
            }
        }

        if ($update !== []) {
            Campaign::query()->whereKey($campaign->getKey())->update([...$update, 'updated_at' => now()]);
        }

        foreach ($variantSent as $variantId => $count) {
            CampaignVariant::query()->whereKey($variantId)->update([
                'sent_count' => DB::raw('sent_count + '.$count),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A KAMPÁNY ZÁRÁSA, MAJD a progressz (65. kritérium) — EBBEN a sorrendben.
     *
     * ## Miért a broadcast van UTOLSÓ helyen, és miért `try`-ban
     *
     * Korábban a broadcast futott előbb, védelem nélkül. Mérve ebben a környezetben
     * `BroadcastException: Pusher error: Not found.` szállt ki a `handle()`-ből — a
     * `complete()` SOHA nem futott le, a kampány `sending`-ben maradt, a job pedig
     * újrapróbált és ugyanott hasalt el. A tesztek `BROADCAST_CONNECTION=null`-lal futnak,
     * ezért ez nem látszott.
     *
     * Az ÁLLAPOT nem függhet egy hálózati hívás sikerétől. A felület egy elmaradt
     * progressz-eseményt túlél (a következő köteg vagy egy oldaltöltés behozza); egy örökre
     * `sending` kampányt nem.
     */
    private function finishOrReport(Campaign $campaign): void
    {
        $campaign = $campaign->fresh();

        if ($campaign === null) {
            return;
        }

        $pending = $campaign->recipients()->where('status', 'pending')->count();

        // AZ UTOLSÓ KÖTEG zárja a kampányt. A batch `finally` callbackje elegánsabb lenne,
        // de az a batch elkészítésekor rögzül — egy folytatás (`resume`) új batchet indít,
        // és a régi callback már nem hívódna meg.
        if ($pending === 0 && (string) $campaign->status === 'sending') {
            app(CampaignLifecycle::class)->complete($campaign);

            $campaign = $campaign->fresh() ?? $campaign;
        }

        // Kötegenként EGYSZER, nem címzettenként: százezer címzettnél a soronkénti esemény
        // a WebSocket-csatornát tömné meg, és a felület egy másodpercen belül több száz
        // frissítést kapna — használhatatlanul.
        try {
            CampaignProgressed::dispatch(
                (string) $campaign->uuid,
                (int) $campaign->workspace_id,
                (int) $campaign->recipient_count,
                (int) $campaign->sent_count,
                (int) $campaign->failed_count,
            );
        } catch (Throwable $e) {
            Log::warning('A kampány-progressz nem ment ki', [
                'campaign_uuid' => (string) $campaign->uuid,
                'exception' => $e::class,
            ]);
        }
    }
}
