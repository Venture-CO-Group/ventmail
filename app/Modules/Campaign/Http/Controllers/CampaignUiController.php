<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Analytics\Services\Rates;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Jobs\RefreshCampaignStats;
use App\Modules\Campaign\Jobs\StartCampaignJob;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Campaign\Services\CampaignChecklist;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\CampaignStateChanged;
use App\Modules\Domains\Models\Domain;
use App\Modules\Template\Data\EditorCatalog;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\TemplateUsage;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\WorkspaceTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * KAMPÁNYOK (62–67. kritérium): lista, szerkesztés, célzás, ütemezés, indítás.
 *
 * ## AZ ÁLLAPOTGÉP a modellben van, nem itt
 *
 * A `CampaignLifecycle` dönti el, mi mire válthat (`draft → scheduled → sending → sent`),
 * és a modell `saving` hookja tiltja a nem szerkeszthető állapotban lévő kampány
 * módosítását. A kontroller ezért CSAK hív: egy külön felületi állapot-logika előbb-utóbb
 * megengedne valamit, amit a motor nem.
 *
 * ## A BECSÜLT KÖZÖNSÉG a célzás mellett látszik
 *
 * Mert a „kinek megy ki?" kérdésre az indítás előtt kell válaszolni. A becslés a VALÓDI
 * feloldón (`AudienceResolver`) megy át — ugyanazon, amelyik a küldéskor is dolgozik.
 */
final class CampaignUiController
{
    /** A lista oldalmérete. */
    private const PER_PAGE = 25;

    /** A lista szűrhető állapotai — a `failed` SZÁRMAZTATOTT (lásd `displayStatus()`). */
    private const FILTERS = ['all', 'draft', 'scheduled', 'sending', 'paused', 'sent', 'canceled', 'failed'];

    /**
     * KAMPÁNYLISTA (v1.1 · 5. modul): állapot-jelvények, szűrők, keresés, sor-statisztika.
     *
     * A statisztika (küldött, nyitás, kattintás) a meglévő analitikából jön: a `sent_count`
     * a kampány-sorból, az egyedi nyitók/kattintók és a kézbesítettek a `campaign_engagement`
     * ténytáblából — EGY csoportosított lekérdezéssel a lap összes kampányára.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('campaign.create');

        $status = in_array($request->string('status')->toString(), self::FILTERS, true) ? $request->string('status')->toString() : 'all';
        $search = mb_substr(trim($request->string('search')->toString()), 0, 120);

        $page = Campaign::query()
            ->with(['template:id,uuid,name'])
            ->withCount('variants')
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->when($status === 'failed', fn (Builder $query) => $this->failedScope($query))
            ->when($status === 'sent', fn (Builder $query) => $query->where('status', 'sent')->whereNot(fn (Builder $inner) => $this->failedScope($inner)))
            ->when(! in_array($status, ['all', 'failed', 'sent'], true), fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $stats = $this->engagementStats(collect($page->items()));

        return Inertia::render('Campaign/Index', [
            'filters' => ['status' => $status, 'search' => $search],
            'campaigns' => collect($page->items())->map(function (Campaign $campaign) use ($stats) {
                $engagement = $stats[(string) $campaign->uuid] ?? ['delivered' => 0, 'opened' => 0, 'clicked' => 0];

                return [
                    'uuid' => (string) $campaign->uuid,
                    'name' => (string) $campaign->name,
                    'subject' => (string) $campaign->subject,
                    'status' => (string) $campaign->status,
                    'displayStatus' => $this->displayStatus($campaign),
                    'template' => $campaign->template?->name,
                    'scheduledAt' => $campaign->scheduled_at?->toIso8601String(),
                    'startedAt' => $campaign->started_at?->toIso8601String(),
                    'timezone' => $campaign->timezone,
                    // A SZERKESZTHETŐSÉG az állapotgépből jön: a felület nem találgat.
                    'editable' => $campaign->statusEnum()->isEditable(),
                    'variants' => (int) $campaign->variants_count,
                    'recipients' => (int) $campaign->recipient_count,
                    'sent' => (int) $campaign->sent_count,
                    // A RÁTÁK nevezője a KÉZBESÍTETT (Rates docblock): a nem kézbesített levél
                    // nem nyitható meg.
                    'openRate' => $engagement['delivered'] > 0 ? Rates::percent($engagement['opened'], $engagement['delivered']) : null,
                    'clickRate' => $engagement['delivered'] > 0 ? Rates::percent($engagement['clicked'], $engagement['delivered']) : null,
                    'progress' => $campaign->recipient_count > 0
                        ? (int) round(($campaign->sent_count + $campaign->failed_count) / $campaign->recipient_count * 100)
                        : 0,
                ];
            })->values()->all(),
            'pagination' => [
                'current' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ]);
    }

    /**
     * A FELÜLETI állapot: a hat gépi állapot + a SZÁRMAZTATOTT `failed`.
     *
     * A `failed` nem állapotgép-állapot (expand-only: az enumot és az átmeneteket nem
     * bővítjük): az a lezárt kampány, aminek volt címzettje, de EGYETLEN levele sem ment ki —
     * jellemzően mert a PreSendGate mindet elutasította (seed-mód, consent, domain). A
     * „Kiküldve" jelvény erre hazugság lenne.
     */
    private function displayStatus(Campaign $campaign): string
    {
        return $campaign->status === 'sent' && (int) $campaign->recipient_count > 0 && (int) $campaign->sent_count === 0
            ? 'failed'
            : (string) $campaign->status;
    }

    /** @param Builder<Campaign> $query */
    private function failedScope(Builder $query): Builder
    {
        return $query->where('status', 'sent')->where('recipient_count', '>', 0)->where('sent_count', 0);
    }

    /**
     * Egyedi kézbesített / nyitó / kattintó címzettek kampányonként — egy lekérdezés a lapra.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<string, array{delivered: int, opened: int, clicked: int}>
     */
    private function engagementStats(Collection $campaigns): array
    {
        $stats = [];

        foreach ($campaigns as $campaign) {
            // A KÜLDÉS NÉLKÜLI kampánynak nincs mit számolni.
            if (! in_array((string) $campaign->status, ['sending', 'paused', 'sent', 'canceled'], true)) {
                continue;
            }

            $cached = Cache::get(RefreshCampaignStats::cacheKey((string) $campaign->uuid));

            // A KÉRÉSBEN SOSEM számolunk (review RS6): a hiányzó érték háttérben töltődik, a
            // sor addig „—"-t mutat.
            if (is_array($cached)) {
                $stats[(string) $campaign->uuid] = $cached;
            } else {
                RefreshCampaignStats::dispatch((string) $campaign->uuid, (int) $campaign->workspace_id);
            }
        }

        return $stats;
    }

    /**
     * KAMPÁNY-LÉTREHOZÓ CHECKLIST (v1.1 · 5. modul).
     *
     * A szakaszok állapota és a szerver-ellenőrzések a `CampaignChecklist`-ből jönnek; a
     * küldés gombja ugyanazon a listán áll, amit a `transition()` az indításnál újra kérdez.
     */
    public function show(Request $request, string $uuid, CampaignChecklist $checklist, WorkspaceContext $context): Response
    {
        Gate::authorize('campaign.create');

        $campaign = Campaign::query()->with(['template', 'variants.template'])->where('uuid', $uuid)->firstOrFail();

        return Inertia::render('Campaign/Edit', [
            'campaign' => [
                'uuid' => (string) $campaign->uuid,
                'name' => (string) $campaign->name,
                'subject' => (string) $campaign->subject,
                'preheader' => $campaign->preheader,
                'fromEmail' => $campaign->from_email,
                'fromName' => $campaign->from_name,
                'status' => (string) $campaign->status,
                'displayStatus' => $this->displayStatus($campaign),
                'editable' => $campaign->statusEnum()->isEditable(),
                'templateUuid' => $campaign->template?->uuid,
                'targetLists' => array_values((array) $campaign->target_lists),
                'targetSegments' => array_values((array) $campaign->target_segments),
                'excludeLists' => array_values((array) $campaign->exclude_lists),
                'excludeSegments' => array_values((array) $campaign->exclude_segments),
                'scheduledAt' => $campaign->scheduled_at?->toIso8601String(),
                'scheduledLocal' => $campaign->scheduled_local ?? $campaign->scheduledLocal()?->format('Y-m-d H:i'),
                'sendMode' => $campaign->send_mode,
                'timezone' => $campaign->timezone,
                'winnerCriterion' => $campaign->winner_criterion,
                'winnerAfterMinutes' => (int) $campaign->winner_after_minutes,
                'recipientCount' => (int) $campaign->recipient_count,
                'sentCount' => (int) $campaign->sent_count,
                'failedCount' => (int) $campaign->failed_count,
                // AZ ÖSSZEG a felületen: az indítás 100-at követel, és egy 90-es összeget
                // nem szabad az indítás hibaüzenetéből megtudni.
                'variantWeightTotal' => (int) $campaign->variants->sum('weight'),
                'variants' => $campaign->variants->map(fn ($variant) => [
                    'uuid' => (string) $variant->uuid,
                    'label' => (string) $variant->label,
                    'subject' => $variant->subject,
                    'fromEmail' => $variant->from_email,
                    'fromName' => $variant->from_name,
                    'template' => $variant->template?->name,
                    // A SÚLY SZÁZALÉK, és az összegüknek PONTOSAN 100-nak kell lennie.
                    'weight' => (int) $variant->weight,
                    'winner' => (bool) $variant->is_winner,
                    'sent' => (int) $variant->sent_count,
                    'opened' => (int) $variant->opened_count,
                    'clicked' => (int) $variant->clicked_count,
                ])->values()->all(),
            ],
            'checklist' => $state = $checklist->evaluate($campaign),
            // A KIMENET címzett-állapot + ok szerint: „miért nem ment ki?" — a PreSendGate
            // elutasításának kódjával (pl. NO_CONSENT, SENDING_RESTRICTED).
            'outcomes' => CampaignRecipient::query()
                ->where('campaign_id', $campaign->getKey())
                ->selectRaw("status, coalesce(skip_reason, '') as reason, count(*) as n")
                ->groupBy('status', 'skip_reason')
                ->orderByDesc('n')
                ->limit(20)
                ->get()
                ->map(fn ($row) => ['status' => (string) $row->status, 'reason' => (string) $row->reason, 'count' => (int) $row->n])
                ->values()->all(),
            'templates' => Template::query()->where('marketing', true)->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Template $template) => ['uuid' => (string) $template->uuid, 'name' => (string) $template->name])
                ->values()->all(),
            'lists' => ContactList::query()->withCount(['contacts' => fn ($query) => $query->where('contact_list_memberships.status', 'subscribed')])
                ->orderBy('name')->get(['id', 'uuid', 'name'])
                ->map(fn (ContactList $list) => ['uuid' => (string) $list->uuid, 'name' => (string) $list->name, 'contacts' => (int) $list->contacts_count])
                ->values()->all(),
            'segments' => Segment::query()->orderBy('name')->get(['uuid', 'name', 'estimated_size'])
                ->map(fn (Segment $segment) => ['uuid' => (string) $segment->uuid, 'name' => (string) $segment->name, 'contacts' => $segment->estimated_size === null ? null : (int) $segment->estimated_size])
                ->values()->all(),
            // A FELADÓ csak HITELESÍTETT domainről választható (golden rule 8).
            'domains' => Domain::query()->where('status', 'verified')->orderBy('name')->pluck('name')->map(fn ($name) => (string) $name)->values()->all(),
            'mergeTags' => array_values(array_filter(EditorCatalog::mergeTags(), static fn (array $tag) => ! ($tag['link'] ?? false))),
            'defaultTimezone' => WorkspaceTimezone::for($context->workspace(), $request->user()),
            // A „KINEK MEGY KI?" kérdésre az indítás ELŐTT kell válaszolni — a checklist
            // ugyanazon feloldásából (review RS4: egy számlálás, nem kettő).
            // `null` = a keretezett becslés nem készült el (review SN3): „becslés nem elérhető".
            'estimate' => $state['sections'][0]['estimate'] ?? null,
        ]);
    }

    public function store(Request $request, WorkspaceContext $context): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', $this->uniqueName(null)],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $campaign = Campaign::create([
            'name' => $validated['name'],
            'subject' => $validated['subject'] ?? '',
            // v1.1 · az ütemezés alapértelmezett zónája a workspace-é (lásd `WorkspaceTimezone`).
            'timezone' => WorkspaceTimezone::for($context->workspace(), $request->user()),
        ]);

        return redirect()->route('campaigns.show', ['uuid' => $campaign->uuid]);
    }

    /**
     * A CHECKLIST mentése — a teljes kampány-állapot egy kérésben.
     *
     * A szakaszok külön-külön menthetők a felületen, de mindig a TELJES űrlapot küldik: két
     * részleges mentés versenye így nem írhat felül egy másik szakaszt félig.
     */
    public function update(Request $request, string $uuid, CampaignChecklist $checklist, CampaignLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', $this->uniqueName($campaign)],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'fromEmail' => ['nullable', 'string', 'email', 'max:320'],
            'fromName' => ['nullable', 'string', 'max:120'],
            'templateUuid' => ['nullable', 'string'],
            'targetLists' => ['array', 'max:50'],
            'targetLists.*' => ['string', 'uuid'],
            'targetSegments' => ['array', 'max:50'],
            'targetSegments.*' => ['string', 'uuid'],
            'excludeLists' => ['array', 'max:50'],
            'excludeLists.*' => ['string', 'uuid'],
            'excludeSegments' => ['array', 'max:50'],
            'excludeSegments.*' => ['string', 'uuid'],
            // v1.1 · ütemezés (most / később) a választott időzónában, és az A/B győztes-szabály.
            'sendMode' => ['nullable', Rule::in(['now', 'later'])],
            'scheduledLocal' => ['nullable', 'required_if:sendMode,later', 'date_format:Y-m-d H:i'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'winnerCriterion' => ['nullable', Rule::in(['open_rate', 'click_rate'])],
            'winnerAfterMinutes' => ['nullable', 'integer', 'min:30', 'max:10080'],
        ]);

        $templateId = null;

        if (! empty($validated['templateUuid'])) {
            // A SABLON a global scope-on át oldódik fel: idegen workspace sablonjára nem
            // lehet kampányt kötni.
            $templateId = Template::query()->where('uuid', $validated['templateUuid'])->firstOrFail()->getKey();
        }

        // SOR-ZÁR a mentés és az ütemezett ág idejére (review NIT): az ütemező (amelyik szintén
        // zárol) és egy másik szerkesztés nem futhat közé — egy közben `sending`-re váltott
        // kampányt a már beolvasott állapot alapján nem írunk át, és nem ütemezünk újra.
        return DB::transaction(function () use ($campaign, $validated, $templateId, $checklist, $lifecycle): RedirectResponse {
            $campaign = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            try {
                $campaign->fill([
                    'name' => $validated['name'],
                    'subject' => $validated['subject'] ?? '',
                    'preheader' => $validated['preheader'] ?? null,
                    'from_email' => $validated['fromEmail'] ?? null,
                    'from_name' => $validated['fromName'] ?? null,
                    'template_id' => $templateId,
                    'target_lists' => array_values($validated['targetLists'] ?? []),
                    'target_segments' => array_values($validated['targetSegments'] ?? []),
                    'exclude_lists' => array_values($validated['excludeLists'] ?? []),
                    'exclude_segments' => array_values($validated['excludeSegments'] ?? []),
                    'winner_criterion' => $validated['winnerCriterion'] ?? null,
                    'winner_after_minutes' => (int) ($validated['winnerAfterMinutes'] ?? $campaign->winner_after_minutes ?? 240),
                ]);

                $campaign->forceFill([
                    'send_mode' => $validated['sendMode'] ?? null,
                    'scheduled_local' => ($validated['sendMode'] ?? null) === 'later' ? ($validated['scheduledLocal'] ?? null) : null,
                    'timezone' => $validated['timezone'] ?? $campaign->timezone,
                ])->save();
            } catch (RuntimeException $e) {
                // A NEM SZERKESZTHETŐ állapotot a MODELL tiltja (küldés közben a célzás
                // átírása fél kampányt küldene máshova). A felület a szabály OKÁT mondja el.
                return back()->with('error', $e->getMessage());
            }

            if ($campaign->status !== 'scheduled') {
                return back()->with('success', __('ventmail.campaigns.saved'));
            }

            // AZ ÜTEMEZÉS a `campaign.publish` képesség (review BN2): a szerző (`author`) írhat
            // kampányt, de nem ütemezhet. Egy ütemezett kampány szerkesztése a jóváhagyott
            // tartalmat és időpontot írja át — publikálási jog nélkül ezért VÁZLATBA kerül
            // (a tartalom megmarad), auditálva; egy publikáló ütemezheti újra.
            if (Gate::denies('campaign.publish')) {
                $lifecycle->unschedule($campaign);
                app(AuditLogger::class)->log('campaign.unscheduled_by_edit', $campaign, context: ['reason' => 'missing_publish_ability']);

                return back()->with('error', __('studio.checklist.unscheduledNoPublish'));
            }

            // AZ ÜTEMEZETT kampány szerkesztése (review RS1): az időpont és a tartalom a checklisttel
            // újra érvényesül. Ha minden kész, ÚJRAÜTEMEZ (az új helyi idő az új UTC-pillanat), ha
            // nem, VISSZAVESZI vázlatba — egy hiányossá szerkesztett kampány nem indulhat el az
            // eredeti időpontban.
            $fresh = $campaign->fresh();

            // Az ÚJRAÜTEMEZÉS is élesítés: a sablon-sorok megosztott zárja (SEC4-S1).
            app(TemplateUsage::class)->lockForActivation($fresh->templateIds());

            try {
                if (! $checklist->evaluate($fresh)['ready'] || $fresh->send_mode !== 'later') {
                    throw new RuntimeException(__('studio.checklist.unscheduled'));
                }

                $lifecycle->schedule($fresh, (string) $fresh->scheduled_local, (string) $fresh->timezone);
            } catch (\Throwable) {
                $lifecycle->unschedule($campaign->fresh());

                return back()->with('error', __('studio.checklist.unscheduled'));
            }

            return back()->with('success', __('ventmail.campaigns.saved'));
        });
    }

    /**
     * A/B VÁLTOZAT hozzáadása (64. kritérium).
     *
     * ## Miért a felületen is, és miért csak DRAFT állapotban
     *
     * Eddig a variánst csak API-ból lehetett létrehozni, a felület pedig CSAK MUTATTA —
     * vagyis az A/B teszt gyakorlatilag fejlesztői funkció volt. Küldés közben viszont új
     * változat nem jöhet: a kiosztás (`VariantAssigner`) a küldés elején dől el, és egy
     * menet közben érkező variáns a már kiküldött felével összehasonlíthatatlan lenne.
     */
    public function addVariant(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        if (! $campaign->statusEnum()->isEditable()) {
            return back()->with('error', __('ventmail.campaigns.variantLocked'));
        }

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            // v1.1 · a variáns TÁRGY-, TARTALOM- vagy FELADÓ-változat (vagy ezek keveréke);
            // legalább egy dimenzióban el kell térnie — egy azonos variáns nem A/B teszt.
            'subject' => ['nullable', 'required_without_all:templateUuid,fromEmail', 'string', 'max:255'],
            'templateUuid' => ['nullable', 'string', 'uuid'],
            'fromEmail' => ['nullable', 'string', 'email', 'max:320'],
            'fromName' => ['nullable', 'string', 'max:120'],
            // A SÚLY SZÁZALÉK. A nulla nem engedett: egy 0%-os változat sosem kapna
            // címzettet, viszont beleszámítana a „legalább két variáns" feltételbe.
            'weight' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        // A SABLON a global scope-on át: idegen workspace sablonja 404.
        $templateId = empty($validated['templateUuid'])
            ? null
            : Template::query()->where('uuid', $validated['templateUuid'])->firstOrFail()->getKey();

        try {
            $campaign->variants()->create([
                'label' => $validated['label'],
                'subject' => ($validated['subject'] ?? '') === '' ? null : $validated['subject'],
                'template_id' => $templateId,
                'from_email' => $validated['fromEmail'] ?? null,
                'from_name' => $validated['fromName'] ?? null,
                'weight' => (int) $validated['weight'],
            ]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.campaigns.variantAdded'));
    }

    /**
     * VÁLTOZAT TÖRLÉSE — piszkozat állapotban.
     *
     * Enélkül a képernyő zsákutca lenne: a súlyok összegének PONTOSAN 100-nak kell lennie
     * az indításhoz, és egy elgépelt súlyú változatot csak API-ból lehetett volna
     * eltüntetni. Küldés közben (vagy után) viszont nem törölhető: a variáns a kiküldött
     * levelek MAGYARÁZATA, és a nélküle maradt statisztika értelmezhetetlen lenne.
     */
    public function deleteVariant(string $uuid, string $variant): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        if (! $campaign->statusEnum()->isEditable()) {
            return back()->with('error', __('ventmail.campaigns.variantLocked'));
        }

        $target = $campaign->variants()->where('uuid', $variant)->first();

        // NEM LÉTEZŐ VÁLTOZAT: siker-üzenet helyett hiba — különben a felhasználó azt
        // hinné, hogy törölt valamit, ami ott sem volt (vagy ami máshoz tartozik).
        if ($target === null) {
            return back()->with('error', __('ventmail.campaigns.variantMissing'));
        }

        $target->delete();

        return back()->with('success', __('ventmail.campaigns.variantRemoved'));
    }

    /**
     * ÁLLAPOTVÁLTÁS — a v1.1 `send` a checklist „Küldés / Ütemezés" gombja.
     *
     * Az INDÍTÁS és az ÜTEMEZÉS előtt a SZERVER újra lefuttatja a checklistet: a felületi
     * gomb letiltása kényelem, ez a határ (szigorú TemplateCompliance, hitelesített domain,
     * megerősített consent). A küldés pillanatában pedig a PreSendGate dönt címzettenként.
     *
     * A `start` (és a `send` „most" ága) a kérésben csak LEFOGLAL, és a `StartCampaignJob`
     * MATERIALIZÁL a `campaign` soron (review RB1/S4): egy 300k-s célzás nem egy webkérés
     * idejében íródik fel. (A v1.1 előtt a felület a puszta `CampaignLifecycle::start()`-ot
     * hívta: a kampány `sending`-re váltott, de egyetlen címzett sem jött létre.)
     */
    public function transition(
        Request $request,
        string $uuid,
        CampaignLifecycle $lifecycle,
        CampaignChecklist $checklist,
    ): RedirectResponse {
        // AZ INDÍTÁS ÉS AZ ÜTEMEZÉS külön képesség: a `author` szerep írhat kampányt, de
        // nem küldheti ki.
        Gate::authorize('campaign.publish');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['send', 'schedule', 'unschedule', 'start', 'pause', 'resume', 'cancel'])],
            'localTime' => ['required_if:action,schedule', 'nullable', 'string'],
            'timezone' => ['required_if:action,schedule', 'nullable', 'string'],
        ]);

        $action = $validated['action'];

        if ($action === 'send') {
            $action = $campaign->send_mode === 'later' ? 'schedule' : 'start';
            $validated['localTime'] = $campaign->scheduled_local;
            $validated['timezone'] = $campaign->timezone;
        }

        // AZ INDÍTÁS ÉS AZ ÜTEMEZÉS SOR-ZÁR ALATT (review S2): a checklist a ZÁROLT, friss soron
        // fut, és ugyanabban a tranzakcióban vált az állapot. A szerkesztés (`update()`) ugyanezt a
        // sort zárolja — így egy közben mentett, ellenőrizetlen tartalom nem ütemeződhet be a
        // korábbi checklist alapján. (A szüneteltetés / folytatás / visszavonás NEM itt zárol: a
        // `CampaignLifecycle` állapotváltásai maguk zárolnak és összehasonlítanak, a folytatás
        // kötegei pedig csak a commit után indulhatnak.)
        try {
            if (in_array($action, ['start', 'schedule'], true)) {
                $blocked = DB::transaction(function () use ($campaign, $action, $validated, $checklist, $lifecycle): ?RedirectResponse {
                    $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

                    if ((string) $locked->status !== (string) $campaign->status) {
                        throw new CampaignStateChanged((string) $campaign->status, (string) $locked->status);
                    }

                    // A HIVATKOZOTT SABLONOK megosztott zárja (review SEC4-S1): a szerkesztő mentése
                    // (FOR UPDATE) ezalatt vár, a checklist így a véglegesen kiküldendő tartalmat látja,
                    // és a commit után a mentés már az aktív használatot (publikálási kaput) látja.
                    app(TemplateUsage::class)->lockForActivation($locked->templateIds());

                    $state = $checklist->evaluate($locked);

                    if (! $state['ready']) {
                        return back()->with('error', __('studio.checklist.notReady', ['blockers' => implode(', ', $state['blockers'])]));
                    }

                    match ($action) {
                        'schedule' => $lifecycle->schedule($locked, (string) $validated['localTime'], (string) $validated['timezone']),
                        // LEFOGLALÁS a kérésben, materializálás + kötegek a `campaign-start` soron (RB1/S4);
                        // a job a commit UTÁN kerül a sorba (`afterCommit`).
                        'start' => StartCampaignJob::dispatch((string) $lifecycle->start($locked)->uuid, (int) $locked->workspace_id),
                    };

                    return null;
                });

                if ($blocked !== null) {
                    return $blocked;
                }
            } else {
                match ($action) {
                    'unschedule' => $lifecycle->unschedule($campaign),
                    'pause' => $lifecycle->pause($campaign),
                    'resume' => $lifecycle->resume($campaign),
                    'cancel' => $lifecycle->cancel($campaign),
                };
            }
        } catch (CampaignStateChanged) {
            // VERSENYHELYZET (review R4-S2): a felhasználó nyelvén, belső állapotnevek nélkül.
            return back()->with('error', __('studio.checklist.changed'));
        } catch (\Throwable $e) {
            // Az állapotgép és a kapuk (hitelesített domain, consent, leiratkozó blokk)
            // hibáit UGYANÚGY jelezzük: a marketinges számára mindkettő ugyanaz a kérdés —
            // „miért nem megy ki?"
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __($action === 'cancel' ? 'studio.checklist.canceled' : 'ventmail.campaigns.'.$action));
    }

    /** A kampány neve tenantonként egyedi (a részleges unique index szerint). */
    private function uniqueName(?Campaign $campaign): Unique
    {
        return Rule::unique('campaigns', 'name')
            ->where('workspace_id', app(WorkspaceContext::class)->workspaceId())
            ->whereNull('deleted_at')
            ->ignore($campaign?->getKey());
    }
}
