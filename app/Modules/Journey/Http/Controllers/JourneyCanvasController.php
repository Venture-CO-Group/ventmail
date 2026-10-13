<?php

declare(strict_types=1);

namespace App\Modules\Journey\Http\Controllers;

use App\Models\User;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Graph\JourneyGraphValidator;
use App\Modules\Journey\Graph\LegacyChainGraph;
use App\Modules\Journey\Graph\NodeCatalog;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyVersion;
use App\Modules\Journey\Services\JourneyGraphInvalid;
use App\Modules\Journey\Services\JourneyNodeStats;
use App\Modules\Journey\Services\JourneyPublisher;
use App\Modules\Journey\Services\JourneySimulator;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Template\Models\Template;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A JOURNEY VÁSZON (v1.1 · 6. modul).
 *
 * Képességek (a kampány mintája — az `author` szerkeszt, nem indít):
 * - szerkesztés, validálás, teszt-futtatás: `campaign.create` (a meglévő journey-szerkesztés
 *   képessége);
 * - PUBLIKÁLÁS (aktiválás / új verzió) és SZÜNETELTETÉS: `journey.publish` — ez küldést indít
 *   vagy állít le, ugyanaz a felelősség, mint a kampány kiküldése. Mindkettő auditált.
 */
final class JourneyCanvasController
{
    /** A teszt-futtatás lekérdezés-büdzséje (ms, utasításonként). */
    private const TEST_RUN_BUDGET_MS = 3000;

    private const OPTION_LIMIT = 200;

    public function show(string $uuid, JourneyGraphValidator $validator, JourneyNodeStats $stats): Response
    {
        Gate::authorize('campaign.create');

        $journey = Journey::query()->with('currentVersion')->where('uuid', $uuid)->firstOrFail();
        $graph = $this->graphOf($journey);

        return Inertia::render('Journey/Canvas', [
            'journey' => [
                'uuid' => (string) $journey->uuid,
                'name' => (string) $journey->name,
                'status' => (string) $journey->status,
                'version' => $journey->currentVersion?->number,
                // VAN-E PUBLIKÁLATLAN változás az aktuális verzióhoz képest?
                'dirty' => $journey->currentVersion === null || $journey->currentVersion->graph_hash !== $graph->hash(),
            ],
            'graph' => $graph->toArray(),
            'catalog' => NodeCatalog::forUi(),
            'options' => $this->options($graph),
            'issues' => $validator->validate($graph),
            'stats' => $stats->forJourney($journey, $graph),
            'versions' => JourneyVersion::query()
                ->where('journey_id', $journey->getKey())
                ->orderByDesc('number')
                ->limit(10)
                ->get(['number', 'published_at'])
                ->map(fn (JourneyVersion $version) => [
                    'number' => (int) $version->number,
                    'publishedAt' => $version->published_at?->toIso8601String(),
                ])->values()->all(),
            'can' => [
                'publish' => Gate::allows('journey.publish'),
                'webhooks' => Gate::allows('webhook.manage'),
                'testRun' => Gate::allows('audience.manage'),
            ],
        ]);
    }

    /** VÁZLAT mentése — mindig menthető, a hibák figyelmeztetésként jönnek vissza. */
    public function save(Request $request, string $uuid, JourneyGraphValidator $validator): JsonResponse
    {
        Gate::authorize('campaign.create');

        $validated = $request->validate([
            'graph' => ['required', 'array'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $journey = Journey::query()->with('currentVersion')->where('uuid', $uuid)->firstOrFail();

        try {
            $graph = JourneyGraph::fromArray($validated['graph']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /*
         | A WEBHOOK-VÉGPONT KIVÁLASZTÁSA `webhook.manage` (review SEC-S2): a végpont egy
         | integráció szerződése, és a journey-esemény a kontakt címét viszi oda. A szerző a már
         | bekötött végpontot megtarthatja, újat (vagy másikat) nem választhat.
         */
        if ($this->webhookDenied($journey, $graph)) {
            return response()->json(['message' => __('ventmail.journeys.webhookForbidden')], 403);
        }

        $journey->forceFill(array_filter([
            'graph' => $graph->toArray(),
            'name' => isset($validated['name']) ? trim($validated['name']) : null,
        ], static fn ($value): bool => $value !== null && $value !== ''))->save();

        return response()->json([
            'issues' => $validator->validate($graph),
            'dirty' => $journey->currentVersion === null || $journey->currentVersion->graph_hash !== $graph->hash(),
        ]);
    }

    /** VALIDÁLÁS mentés nélkül. */
    public function validateGraph(Request $request, string $uuid, JourneyGraphValidator $validator): JsonResponse
    {
        Gate::authorize('campaign.create');

        Journey::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $graph = JourneyGraph::fromArray((array) $request->input('graph', []));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['issues' => $validator->validate($graph)]);
    }

    /**
     * PUBLIKÁLÁS: aktiválás vagy — aktív journey-nél — ÚJ VERZIÓ. A bent lévő kontaktok a régi
     * verzión haladnak tovább.
     */
    public function publish(Request $request, string $uuid, JourneyPublisher $publisher): JsonResponse
    {
        Gate::authorize('journey.publish');

        $journey = Journey::query()->where('uuid', $uuid)->firstOrFail();

        if ($request->has('graph')) {
            try {
                $graph = JourneyGraph::fromArray((array) $request->input('graph'));
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            if ($this->webhookDenied($journey, $graph)) {
                return response()->json(['message' => __('ventmail.journeys.webhookForbidden')], 403);
            }

            $journey->forceFill(['graph' => $graph->toArray()])->save();
        }

        try {
            /** @var User|null $actor */
            $actor = $request->user();
            $version = $publisher->publish($journey, $actor);
        } catch (JourneyGraphInvalid $e) {
            return response()->json(['message' => __('ventmail.journeys.publishInvalid'), 'issues' => $e->issues], 422);
        }

        return response()->json([
            'message' => __('ventmail.journeys.published', ['version' => $version->number]),
            'version' => (int) $version->number,
            'status' => 'active',
        ]);
    }

    /** SZÜNETELTETÉS: a futások ott maradnak, ahol vannak, és a folytatáskor onnan mennek tovább. */
    public function pause(Request $request, string $uuid, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        Gate::authorize('journey.publish');

        $journey = Journey::query()->where('uuid', $uuid)->firstOrFail();

        /*
         | SOR-ZÁR + ÖSSZEHASONLÍTÁS (review N6, a D124 kampány-mintája): a publikálás ugyanezt a
         | sort zárolja, tehát egy közben futó publikálás és a szüneteltetés nem írhatja felül
         | egymást, és csak AKTÍV journey szüneteltethető.
         */
        $paused = DB::transaction(function () use ($journey, $audit): bool {
            $locked = Journey::query()->whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                return false;
            }

            $locked->forceFill(['status' => 'paused'])->save();

            $audit->log('journey.paused', $locked, context: ['version' => $locked->currentVersion?->number]);

            return true;
        });

        if (! $paused) {
            return $request->expectsJson()
                ? response()->json(['message' => __('ventmail.journeys.notActive')], 409)
                : back()->with('error', __('ventmail.journeys.notActive'));
        }

        return $request->expectsJson()
            ? response()->json(['message' => __('ventmail.journeys.paused'), 'status' => 'paused'])
            : back()->with('success', __('ventmail.journeys.paused'));
    }

    /**
     * TESZT-FUTTATÁS: egy kontakt útja, gyorsított időben, VALÓDI KÜLDÉS NÉLKÜL (a szimulátor
     * csak olvas — lásd `JourneySimulator`).
     */
    public function testRun(Request $request, string $uuid, JourneySimulator $simulator, AuditLogger $audit): JsonResponse
    {
        /*
         | A TESZT-FUTTATÁS `audience.manage` (review 2, B1 — D159). Az út maga is orákulum: a
         | feltétel utáni csomópont elárulja az igaz / hamis eredményt (címke, mező, szegmens,
         | nyitás), a „nincs ilyen kontakt" a létezést. Ezért a szerző (aki a közönséget nem
         | látja) nem kap kontakt-alapú választ — sem részleteset, sem „csak utat".
         */
        Gate::authorize('campaign.create');
        Gate::authorize('audience.manage');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'graph' => ['sometimes', 'array'],
        ]);

        $journey = Journey::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $graph = isset($validated['graph']) ? JourneyGraph::fromArray($validated['graph']) : $this->graphOf($journey);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // A lekérdezések QueryBudget alatt futnak (review SEC-S3).
        try {
            $result = app(QueryBudget::class)->run(fn () => $simulator->run($graph, (string) $validated['email']), self::TEST_RUN_BUDGET_MS);
        } catch (QueryTooExpensive) {
            return response()->json(['message' => __('ventmail.journeys.testRunTooExpensive')], 422);
        }

        $audit->log('journey.test_run', $journey, context: [
            // A KONTAKT pszeudonim azonosítója (review SEC-S1): visszakereshető, ki kit tesztelt,
            // nyers cím nélkül.
            'contact_hash' => KeyedHash::of('email', DomainNormalizer::listKey(trim((string) $validated['email']))),
            'steps' => count($result['steps']),
            'would_send' => $result['sends'],
            'stopped' => $result['stopped'],
        ]);

        return response()->json($result);
    }

    /**
     * Új (vagy másik) webhook-végpontot választana-e valaki `webhook.manage` nélkül?
     */
    private function webhookDenied(Journey $journey, JourneyGraph $graph): bool
    {
        if (Gate::allows('webhook.manage')) {
            return false;
        }

        $before = self::endpointsOf(is_array($journey->graph) ? JourneyGraph::fromArray($journey->graph) : JourneyGraph::empty());

        return array_diff(self::endpointsOf($graph), $before) !== [];
    }

    /** @return list<string> a gráf webhook-csomópontjainak végpontjai */
    private static function endpointsOf(JourneyGraph $graph): array
    {
        return array_values(array_unique(array_map(
            static fn (array $node): string => strtolower(trim((string) ($node['config']['endpoint'] ?? ''))),
            array_filter($graph->nodes, static fn (array $node): bool => $node['type'] === 'webhook'),
        )));
    }

    private function graphOf(Journey $journey): JourneyGraph
    {
        if (is_array($journey->graph)) {
            return JourneyGraph::fromArray($journey->graph);
        }

        // A v1.1 ELŐTTI, láncolt journey a vásznon is megnyílik: a lánc gráffá alakul. A
        // publikálásig a régi lépések futnak tovább.
        return LegacyChainGraph::from($journey);
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    private function options(JourneyGraph $graph): array
    {
        $endpoints = array_values(array_filter(self::endpointsOf($graph), static fn (string $uuid): bool => Str::isUuid($uuid)));

        $pick = static fn ($query, string $label = 'name'): array => $query
            ->orderBy($label)
            ->limit(self::OPTION_LIMIT)
            ->get(['uuid', $label])
            ->map(static fn ($row): array => ['value' => (string) $row->uuid, 'label' => (string) $row->{$label}])
            ->values()
            ->all();

        return [
            'templates' => $pick(Template::query()),
            'lists' => $pick(ContactList::query()),
            'tags' => $pick(ContactTag::query()),
            'segments' => $pick(Segment::query()),
            'forms' => $pick(Form::query()),
            // Végpontot csak `webhook.manage` választhat (review SEC-S2); a szerző a már bekötötteket látja.
            'endpoints' => $pick(WebhookEndpoint::query()->where('active', true)
                ->when(Gate::denies('webhook.manage'), fn ($query) => $query->whereIn('uuid', $endpoints))),
            'campaigns' => Campaign::query()->latest('id')->limit(self::OPTION_LIMIT)->get(['uuid', 'name'])
                ->map(static fn ($row): array => ['value' => (string) $row->uuid, 'label' => (string) $row->name])->values()->all(),
            'routes' => $pick(InboundRoute::query(), 'description'),
            'fields' => [
                ...array_map(static fn (string $key): array => ['value' => $key, 'label' => $key], ['first_name', 'last_name', 'company', 'phone']),
                ...ContactField::query()->orderBy('label')->limit(self::OPTION_LIMIT)->get(['key', 'label'])
                    ->map(static fn ($row): array => ['value' => (string) $row->key, 'label' => (string) $row->label])->values()->all(),
            ],
        ];
    }
}
