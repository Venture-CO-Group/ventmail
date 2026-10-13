<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Analytics\Services\AnalyticsSections;
use App\Modules\Audience\Models\Contact;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignRowStats;
use App\Modules\Domains\Models\Domain;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Support\WorkspaceTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A NYITÓKÉPERNYŐ — az a négy szám, amiért egy küldő reggel belép.
 *
 * ## Miért ez a négy
 *
 * Mert ezek MEGÁLLÍTJÁK a küldést, ha elromlanak: hitelesítetlen domain (1. kapu),
 * elfogyott kvóta (4. kapu), növekvő tiltólista (2. kapu), romló kézbesítési arány. Egy
 * nyitóképernyő, ami „üdvözlünk"-öt ír ki, egy nappal később derít fényt ugyanerre.
 *
 * ## A SZÁMOK UGYANONNAN JÖNNEK, mint a kapuk
 *
 * A kvóta az `EntitlementChecker`-ből (tehát a plan-override-ot is látja), a
 * kézbesítési arányok a `message_event_daily` aggregátumból — ugyanabból, amit a
 * tag-statisztika és a marketing-riport használ. Egy külön „dashboard-lekérdezés" előbb-
 * utóbb más számot mutatna, mint a riport, és akkor egyik sem hihető.
 *
 * ## v1.1 · D284 — a mockup áttekintése
 *
 * KPI-sor sparkline-nal (`AnalyticsSections::overview` — QueryBudget + 5 perces gyorsítótár),
 * legutóbbi kampányok (`CampaignRowStats`, ugyanaz, mint a kampánylista), kézbesíthetőség-kártya
 * (arányok + a domain-rekordok összesített állapota) és teendők. Minden blokk a SAJÁT képessége
 * mögött: amit a szerep a saját képernyőjén nem láthat, azt itt `null`-ként kapja.
 */
final class DashboardController
{
    /** Az áttekintés időszaka (nap) — a mockup „elmúlt 30 nap"-ja. */
    private const DAYS = '30';

    /** A „Legutóbbi kampányok" sorai. */
    private const RECENT_CAMPAIGNS = 5;

    public function __invoke(
        Request $request,
        WorkspaceContext $context,
        EntitlementChecker $entitlements,
        TagStatistics $statistics,
        AnalyticsSections $sections,
        CampaignRowStats $rowStats,
    ): Response {
        $workspace = $context->workspace();

        $domains = Domain::query()->get(['status', 'spf_status', 'dkim_status', 'dmarc_status', 'tracking_enabled', 'tracking_status']);

        $mayAnalyse = Gate::allows('analytics.view');
        $kpis = $mayAnalyse
            ? $sections->overview(AnalyticsRange::resolve(self::DAYS, null, null, WorkspaceTimezone::for($workspace, $request->user())))
            : null;

        // A NAPLÓT nem mindenki láthatja (az `AbilityMap` fehérlistás, az `author` szerep
        // nem kap `logs.view`-t) — a nyitóképernyő nem lehet kiskapu a naplóhoz.
        $mayViewLogs = Gate::allows('logs.view');

        return Inertia::render('Dashboard', [
            'quota' => [
                'remaining' => $entitlements->remainingQuota($workspace),
            ],
            'domains' => [
                'total' => $domains->count(),
                'verified' => $domains->where('status', 'verified')->count(),
                'failed' => $domains->where('status', 'failed')->count(),
            ],
            'suppressions' => Suppression::query()->count(),
            'delivery' => $mayViewLogs ? $this->delivery($statistics) : null,
            'recent' => $mayViewLogs ? $this->recent() : [],
            'workspaceName' => (string) $workspace->name,
            'kpis' => $kpis,
            'campaigns' => Gate::allows('campaign.create') ? $this->campaigns($rowStats) : null,
            'deliverability' => $mayAnalyse ? $this->deliverability($kpis ?? [], $domains) : null,
            'todos' => $this->todos($domains),
            // CSAK MEGJELENÍTÉS: a küldési mód (a tulajdonos kapcsolja, RUNBOOK-mail §13).
            'sendingMode' => [
                'live' => (bool) config('sending.live'),
                'allowlistEnforced' => ! (bool) config('sending.live') || (bool) config('sending.allowlist_enforced', true),
                'marketingEnabled' => (bool) config('sending.marketing_enabled'),
            ],
        ]);
    }

    /**
     * A LEGUTÓBBI KAMPÁNYOK — ugyanaz a sor-statisztika, mint a kampánylistán.
     *
     * @return list<array<string, mixed>>
     */
    private function campaigns(CampaignRowStats $rowStats): array
    {
        $campaigns = Campaign::query()->orderByDesc('id')->limit(self::RECENT_CAMPAIGNS)->get();
        $stats = $rowStats->engagement($campaigns);

        return $campaigns->map(fn (Campaign $campaign) => [
            'uuid' => (string) $campaign->uuid,
            'name' => (string) $campaign->name,
            'displayStatus' => $rowStats->displayStatus($campaign),
            'recipients' => (int) $campaign->recipient_count,
            ...$rowStats->rates($stats[(string) $campaign->uuid] ?? null),
        ])->values()->all();
    }

    /**
     * KÉZBESÍTHETŐSÉG: az időszak bounce- és panaszaránya + a domain-rekordok ÖSSZESÍTETT állapota
     * (rekordtípusonként a legrosszabb: egy hibás domain elég, hogy a sor piros legyen).
     *
     * @param  array<string, mixed>  $kpis
     * @param  Collection<int, Domain>  $domains
     * @return array<string, mixed>
     */
    private function deliverability(array $kpis, Collection $domains): array
    {
        $records = [
            'spf' => $this->worst($domains->pluck('spf_status')->all()),
            'dkim' => $this->worst($domains->pluck('dkim_status')->all()),
            'dmarc' => $this->worst($domains->pluck('dmarc_status')->all()),
            // A tracking CNAME opcionális: csak a bekapcsolt követésű domaineken számít.
            'tracking' => $this->worst($domains->where('tracking_enabled', true)->pluck('tracking_status')->all(), 'optional'),
        ];

        $bounce = $kpis['bounceRate'] ?? null;
        $complaint = $kpis['complaintRate'] ?? null;

        return [
            'available' => (bool) ($kpis['available'] ?? false),
            'bounceRate' => $bounce,
            'complaintRate' => $complaint,
            'bounceThreshold' => AnalyticsSections::BOUNCE_THRESHOLD,
            'complaintThreshold' => AnalyticsSections::COMPLAINT_THRESHOLD,
            'records' => $records,
            'healthy' => ! in_array('failed', $records, true)
                && ($bounce === null || $bounce < AnalyticsSections::BOUNCE_THRESHOLD)
                && ($complaint === null || $complaint < AnalyticsSections::COMPLAINT_THRESHOLD),
        ];
    }

    /** @param  list<mixed>  $statuses */
    private function worst(array $statuses, string $empty = 'unknown'): string
    {
        if ($statuses === []) {
            return $empty;
        }

        foreach (['failed', 'pending', 'propagating'] as $state) {
            if (in_array($state, $statuses, true)) {
                return $state;
            }
        }

        return in_array('verified', $statuses, true) ? 'verified' : 'unknown';
    }

    /**
     * TEENDŐK — csak amit a szerep a saját képernyőjén is kezelhet; minden sor egy linkkel.
     *
     * @param  Collection<int, Domain>  $domains
     * @return list<array{key: string, count: int, href: string, tone: string}>
     */
    private function todos(Collection $domains): array
    {
        $todos = [];

        if (Gate::allows('campaign.create')) {
            $todos[] = ['key' => 'drafts', 'count' => Campaign::query()->where('status', 'draft')->count(), 'href' => '/campaigns?status=draft'];
        }

        // A hitelesítetlen domain AKADÁLY (1. kapu) — mindenki látja, mint a figyelmeztetést.
        $todos[] = ['key' => 'unverifiedDomains', 'count' => $domains->where('status', '!=', 'verified')->count(), 'href' => '/domains'];

        if (Gate::allows('audience.manage')) {
            $todos[] = ['key' => 'pendingContacts', 'count' => Contact::query()->where('status', 'pending')->count(), 'href' => '/audience'];
        }

        if (Gate::allows('webhook.manage') && WebhookEndpoint::query()->exists()) {
            $todos[] = [
                'key' => 'deadLetters',
                'count' => WebhookDelivery::query()->where('status', 'dead_letter')->where('dead_lettered_at', '>=', now()->subDays(7))->count(),
                'href' => '/webhooks',
            ];
        }

        return array_map(static fn (array $todo): array => [...$todo, 'tone' => $todo['count'] > 0 ? 'warning' : 'success'], $todos);
    }

    /**
     * A HÉT NAP összesítése — tagokra bontva jön, itt összeadva.
     *
     * @return array{delivered: int, opened: int, clicked: int, bounced: int, bounce_rate: float}
     */
    private function delivery(TagStatistics $statistics): array
    {
        $rows = $statistics->forRange(now()->subDays(7), now());

        $totals = ['delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }
        }

        $base = $totals['delivered'] + $totals['bounced'];

        return [
            ...$totals,
            // A BOUNCE-ARÁNY nevezője a megkísérelt kézbesítés, nem a sikeres: különben a
            // rosszabb szám nézne ki szebben.
            'bounce_rate' => $base === 0 ? 0.0 : round($totals['bounced'] / $base * 100, 2),
        ];
    }

    /** @return list<array{uuid: string, subject: string, status: string, recipients: list<string>, created_at: ?string}> */
    private function recent(): array
    {
        return Message::query()
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Message $message) => [
                'uuid' => (string) $message->uuid,
                'subject' => (string) $message->subject,
                'status' => (string) $message->status,
                'recipients' => array_values((array) $message->recipients),
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
