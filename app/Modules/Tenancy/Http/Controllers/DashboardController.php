<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Domains\Models\Domain;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Services\WorkspaceContext;
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
 */
final class DashboardController
{
    public function __invoke(
        WorkspaceContext $context,
        EntitlementChecker $entitlements,
        TagStatistics $statistics,
    ): Response {
        $workspace = $context->workspace();

        $domains = Domain::query()->get(['status']);

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
        ]);
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
