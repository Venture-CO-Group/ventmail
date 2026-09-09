<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Tenancy\Services\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tag-szintű statisztika (26. kritérium) a FOLYTONOS AGGREGÁTUMBÓL.
 *
 * Miért nem a nyers eseményekből: mert a retenció azokat törli (25. kritérium). Futásidejű
 * számolással a 90. napon minden korábbi kampány eredménye eltűnne a felületről — az
 * aggregátum viszont megmarad. Aki ezt a szolgáltatást megkerüli, az egy önmagát törlő
 * statisztikát épít.
 *
 * A SZŰRÉS ITT TÖRTÉNIK, nem a hívóban. A `message_event_daily` egy NÉZET: nincs rajta
 * Eloquent-modell, tehát nincs global scope sem — egyetlen elfelejtett `where` cross-tenant
 * számot adna. Ezért a workspace-szűrő ebbe a szolgáltatásba van bezárva, és a kontextus
 * hiánya KIVÉTEL, nem „minden tenant".
 */
final class TagStatistics
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * EGY TAG NAPI IDŐSORA — a folytonos aggregátumból.
     *
     * ## Miért ITT van, és nem a B4 Analytics-ában
     *
     * Mert a `message_event_daily` NÉZET gazdája ez a szolgáltatás („egy képesség, egy
     * gazda"): a nézeten nincs Eloquent-modell, tehát global scope sem, és a workspace-
     * szűrő EGYETLEN helyre van bezárva. A B4 marketing-riportja először maga kérdezte a
     * nézetet — az arch-őr (`ScopeBypassGuardTest`) helyesen tüzelt rá, mert az egy
     * MÁSODIK hely lett volna, ahol egy elfelejtett `where` cross-tenant számot adna.
     *
     * ## Amit NEM ad: EGYEDI számokat
     *
     * A Timescale folytonos aggregátum nem támogat `DISTINCT`-et, tehát ez a metódus
     * ESEMÉNY-számokat ad. Ez nem hiányosság: a napi „hányan" amúgy sem összegezhető (aki
     * két napon nyit, két napba tartozik, de egy ember). Az egyedi számokat a B4
     * ténytáblája adja.
     *
     * @return list<array{day: string, delivered: int, opened: int, clicked: int}>
     */
    public function dailyForTag(string $tag): array
    {
        if (! $this->context->hasWorkspace()) {
            // Fail-closed, ugyanaz az indok, mint a `forRange()`-ben.
            throw new RuntimeException('A napi tag-idősor workspace-kontextus nélkül nem kérdezhető le.');
        }

        return DB::table('message_event_daily')
            ->where('workspace_id', $this->context->workspaceId())
            ->where('tag', $tag)
            ->selectRaw("to_char(day, 'YYYY-MM-DD') as day")
            ->selectRaw("coalesce(sum(total) filter (where type = 'delivered'), 0) as delivered")
            ->selectRaw("coalesce(sum(total) filter (where type = 'opened'), 0) as opened")
            ->selectRaw("coalesce(sum(total) filter (where type = 'clicked'), 0) as clicked")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'delivered' => (int) $row->delivered,
                'opened' => (int) $row->opened,
                'clicked' => (int) $row->clicked,
            ])
            ->all();
    }

    /**
     * @return list<array{tag: string, delivered: int, opened: int, clicked: int, bounced: int, open_rate: float, click_rate: float, bounce_rate: float}>
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to, ?string $channel = null): array
    {
        if (! $this->context->hasWorkspace()) {
            // Fail-closed. A „nincs kontextus → nincs szűrő" viselkedés itt a teljes platform
            // adatát adná vissza egyetlen ügyfél képernyőjére.
            throw new RuntimeException('A tag-statisztika workspace-kontextus nélkül nem kérdezhető le.');
        }

        $rows = DB::table('message_event_daily')
            ->where('workspace_id', $this->context->workspaceId())
            ->whereBetween('day', [$from, $to])
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->groupBy('tag', 'type')
            ->get(['tag', 'type', DB::raw('sum(total) as total')]);

        $byTag = [];

        foreach ($rows as $row) {
            $byTag[$row->tag] ??= ['delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];

            if (array_key_exists($row->type, $byTag[$row->tag])) {
                $byTag[$row->tag][$row->type] += (int) $row->total;
            }
        }

        ksort($byTag);

        return array_values(array_map(
            fn (array $counts, string $tag) => [
                'tag' => $tag,
                ...$counts,
                // Az arány NEVEZŐJE a `delivered`, nem a `accepted`: ami el sem jutott a
                // postafiókig, azt nem lehet megnyitni. Nulla kézbesítésnél az arány 0.0 —
                // nem „nincs adat", mert a felületen a nullát is látni kell.
                'open_rate' => $this->rate($counts['opened'], $counts['delivered']),
                'click_rate' => $this->rate($counts['clicked'], $counts['delivered']),
                'bounce_rate' => $this->rate($counts['bounced'], $counts['delivered'] + $counts['bounced']),
            ],
            $byTag,
            array_keys($byTag),
        ));
    }

    /**
     * Az arány a NYITÁS-ESEMÉNYEK számából jön, nem egyedi címzettekből.
     *
     * Ez az iparági „total open rate", és tudni kell róla, hogy FELFELÉ torzít: az Apple Mail
     * adatvédelmi funkciója (MPP) a címzett helyett tölti be a képeket. A KLIKK az, ami emberi
     * szándékot mér — a felület ezért azt emeli ki.
     */
    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator * 100, 2);
    }
}
