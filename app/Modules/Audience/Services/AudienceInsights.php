<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * KÖZÖNSÉG-DASHBOARD aggregátumai (v1.1 · 5. modul): növekedés, forrás, engagement.
 *
 * ## Szerveroldali aggregátum, chart-könyvtár-független alak
 *
 * Minden szám egy-egy `GROUP BY` lekérdezésből jön (kontaktonkénti ciklus nincs), és a
 * kimenet egyszerű sorlista (`[{date, new, unsubscribed, net}]`, `[{key, count}]`) — a 8.
 * modul ECharts-a és a mostani token-sávok ugyanazt olvassák.
 *
 * ## Költség 300 000 kontaktnál
 *
 * - növekedés: a `(workspace_id, created_at)` és a részleges `(workspace_id, unsubscribed_at)`
 *   indexen, csak az ablak sorait olvassa;
 * - forrás: egy menet a tenant kontaktjain + a consent-sor az egyedi indexen (csak ahol a
 *   `contacts.source` még NULL — a v1.1 előtti soroknál);
 * - engagement: a ténytábla címzettenkénti összevonása (hash-join), egy menet.
 *
 * Mindhárom a `Contact` / `CampaignEngagement` global scope-ján megy: a tenant-szűrőt nem a
 * hívó adja.
 */
final class AudienceInsights
{
    /** Az „aktív" engagement ablaka napokban (megnyitás vagy kattintás). */
    public const ACTIVE_WINDOW_DAYS = 90;

    /** A dashboard gyorsítótárának élettartama (mp). */
    public const CACHE_SECONDS = 300;

    /** @var list<int> a választható ablakok (napok) */
    public const WINDOWS = [7, 30, 90, 365];

    /**
     * @return array{
     *     days: int,
     *     timezone: string,
     *     totals: array<string, int>,
     *     growth: list<array{date: string, new: int, unsubscribed: int, net: int}>,
     *     sources: list<array{key: string, count: int}>,
     *     engagement: list<array{key: string, count: int}>,
     *     activeWindowDays: int
     * }
     */
    public function summary(int $days, string $timezone): array
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;

        // GYORSÍTÓTÁR workspace-enként (review RS5): a három aggregátum 300k kontaktnál is
        // másodperc alatti, de minden oldalbetöltésnél felesleges. 5 perc: a dashboard trend,
        // nem valós idejű számláló. A kulcs a workspace, az ablak és az időzóna.
        $workspaceId = (int) app(WorkspaceContext::class)->workspaceId();

        return Cache::remember(
            sprintf('audience-insights:%d:%d:%s', $workspaceId, $days, $timezone),
            self::CACHE_SECONDS,
            fn (): array => $this->compute($days, $timezone),
        );
    }

    /** @return array<string, mixed> */
    private function compute(int $days, string $timezone): array
    {
        return [
            'days' => $days,
            'timezone' => $timezone,
            'totals' => $this->totals(),
            'growth' => $this->growth($days, $timezone),
            'sources' => $this->sources(),
            'engagement' => $this->engagement(),
            'activeWindowDays' => self::ACTIVE_WINDOW_DAYS,
        ];
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $byStatus = Contact::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        return [
            'total' => (int) $byStatus->sum(),
            'subscribed' => (int) ($byStatus['subscribed'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'unsubscribed' => (int) ($byStatus['unsubscribed'] ?? 0),
            'cleaned' => (int) ($byStatus['cleaned'] ?? 0),
        ];
    }

    /**
     * NAPI bontás a workspace időzónájában — üres napok nullával kitöltve.
     *
     * Az „új" a LÉTREHOZÁS napja (minden forrásból), a „leiratkozott" a leiratkozás napja; a
     * nettó a kettő különbsége. A törölt (GDPR-anonimizált) kontakt nem számít — a global
     * soft-delete szűrő miatt.
     *
     * @return list<array{date: string, new: int, unsubscribed: int, net: int}>
     */
    public function growth(int $days, string $timezone): array
    {
        $start = Carbon::now($timezone)->startOfDay()->subDays($days - 1);
        $from = $start->copy()->utc();

        // A `created_at` időzóna NÉLKÜLI (UTC-ben írt) oszlop: előbb UTC-nek jelöljük, utána
        // váltunk a workspace zónájára. Az `unsubscribed_at` `timestamptz`.
        $new = $this->daily(
            Contact::query()->where('created_at', '>=', $from->toDateTimeString()),
            "(created_at at time zone 'UTC') at time zone ?",
            $timezone,
        );

        $unsubscribed = $this->daily(
            Contact::query()->whereNotNull('unsubscribed_at')->where('unsubscribed_at', '>=', $from),
            'unsubscribed_at at time zone ?',
            $timezone,
        );

        $rows = [];

        for ($day = $start->copy(), $i = 0; $i < $days; $i++, $day->addDay()) {
            $key = $day->toDateString();
            $added = $new[$key] ?? 0;
            $lost = $unsubscribed[$key] ?? 0;

            $rows[] = ['date' => $key, 'new' => $added, 'unsubscribed' => $lost, 'net' => $added - $lost];
        }

        return $rows;
    }

    /**
     * FORRÁS-bontás — ugyanaz a kifejezés, mint a szegmens `source` mezőjéé.
     *
     * @return list<array{key: string, count: int}>
     */
    public function sources(): array
    {
        $counts = Contact::query()
            ->selectRaw(SegmentEngine::SOURCE_EXPRESSION.' as origin, count(*) as n')
            ->groupByRaw('1')
            ->pluck('n', 'origin');

        return array_map(
            static fn (string $key): array => ['key' => $key, 'count' => (int) ($counts[$key] ?? 0)],
            SegmentEngine::SOURCE_VALUES,
        );
    }

    /**
     * ENGAGEMENT-szintek a marketingre elérhető kontaktokon.
     *
     * - `active`: megnyitott vagy kattintott az elmúlt 90 napban;
     * - `inactive`: valaha reagált, de 90 napon belül nem;
     * - `never_opened`: kapott levelet, de soha nem nyitotta meg;
     * - `not_mailed`: még nem kapott kampány- vagy journey-levelet.
     *
     * @return list<array{key: string, count: int}>
     */
    public function engagement(): array
    {
        $since = now()->subDays(self::ACTIVE_WINDOW_DAYS);

        $perRecipient = CampaignEngagement::query()
            ->selectRaw('recipient_hash, max(greatest(coalesce(last_opened_at, first_opened_at), coalesce(last_clicked_at, first_clicked_at))) as last_engaged')
            ->where(fn (Builder $query) => $query
                ->where('accepted', '>', 0)
                ->orWhere('delivered', '>', 0)
                ->orWhere('opens', '>', 0)
                ->orWhere('clicks', '>', 0))
            ->groupBy('recipient_hash');

        $counts = Contact::query()
            ->reachable()
            ->leftJoinSub($perRecipient, 'e', 'e.recipient_hash', '=', 'contacts.email_hash')
            ->selectRaw(
                "case when e.recipient_hash is null then 'not_mailed' when e.last_engaged >= ? then 'active' "
                ."when e.last_engaged is not null then 'inactive' else 'never_opened' end as tier, count(*) as n",
                [$since],
            )
            ->groupByRaw('1')
            ->pluck('n', 'tier');

        return array_map(
            static fn (string $key): array => ['key' => $key, 'count' => (int) ($counts[$key] ?? 0)],
            ['active', 'inactive', 'never_opened', 'not_mailed'],
        );
    }

    /**
     * @param  Builder<Contact>  $query
     * @return array<string, int>
     */
    private function daily(Builder $query, string $expression, string $timezone): array
    {
        return $query
            ->selectRaw("to_char({$expression}, 'YYYY-MM-DD') as day, count(*) as n", [$timezone])
            ->groupByRaw('1')
            ->pluck('n', 'day')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
