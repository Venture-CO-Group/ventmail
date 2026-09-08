<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\IpPools\Models\IpAddress;
use Illuminate\Support\Facades\Log;

/**
 * A küldő IP-k FIGYELÉSE a feketelistákon (43. kritérium).
 *
 * Ez az a hiba, amit MI veszünk észre utoljára: az ügyfél előbb — nála hirtelen senki nem
 * kapja meg a levelet. A monitor nem javít, hanem SZÓL; a javítás (delisting kérelem,
 * IP-csere, forgalom-visszafogás) emberi döntés, és az idő számít.
 *
 * A RIASZTÁS csak ÁLLAPOTVÁLTÁSKOR megy ki. Egy óránként ismételt „még mindig listán vagyunk"
 * üzenet két nap alatt megtanítja az ügyeletet, hogy figyelmen kívül hagyja a riasztásainkat
 * — és akkor a következő, VALÓDI is elvész.
 */
final class BlocklistMonitor
{
    public function __construct(private readonly BlocklistClient $client) {}

    /**
     * @return array{checked: int, listed: int, inconclusive: int, alerts: list<string>}
     */
    public function run(): array
    {
        $zones = (array) config('deliverability.blocklists.zones');

        $summary = ['checked' => 0, 'listed' => 0, 'inconclusive' => 0, 'alerts' => []];

        // A `withoutGlobalScopes` SZÁNDÉKOSAN nincs itt: az `IpAddress` platform-entitás,
        // nem tenant-adat.
        IpAddress::query()->orderBy('id')->each(function (IpAddress $ip) use ($zones, &$summary): void {
            foreach ($zones as $zone) {
                $verdict = $this->client->check((string) $ip->address, (string) $zone);

                $summary['checked']++;

                if (! $verdict->conclusive) {
                    // A „nem tudtuk megkérdezni" NEM tiszta. Rögzítjük, de nem riasztunk:
                    // a hamis riasztás ugyanúgy rombolja a bizalmat, mint az elmaradt.
                    $summary['inconclusive']++;

                    continue;
                }

                $previous = BlocklistCheck::query()
                    ->where('target', $ip->address)
                    ->where('zone', $zone)
                    ->orderByDesc('id')
                    ->first();

                BlocklistCheck::query()->create([
                    'ip_address_id' => $ip->getKey(),
                    'target' => $ip->address,
                    'zone' => $zone,
                    'listed' => $verdict->listed,
                    'detail' => $verdict->detail,
                    'checked_at' => now(),
                ]);

                if ($verdict->listed) {
                    $summary['listed']++;
                }

                // CSAK ÁLLAPOTVÁLTÁSKOR riasztunk — az első alkalommal is, mert olyankor
                // nincs korábbi állapot, amihez képest „nem változott".
                if ($verdict->listed !== (bool) ($previous?->listed ?? false)) {
                    $message = $verdict->listed
                        ? sprintf('A(z) %s IP FELKERÜLT a(z) %s listára: %s', $ip->address, $zone, $verdict->detail)
                        : sprintf('A(z) %s IP LEKERÜLT a(z) %s listáról.', $ip->address, $zone);

                    $summary['alerts'][] = $message;

                    Log::warning('Blocklist-állapotváltás.', ['target' => $ip->address, 'zone' => $zone, 'listed' => $verdict->listed]);
                }
            }
        });

        return $summary;
    }
}
