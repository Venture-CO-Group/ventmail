<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Services;

use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Melyik IP-ről menjen ki az üzenet (16–17. kritérium).
 *
 * A REPUTÁCIÓ AZ IP-HEZ TAPAD, nem a tenanthoz — ez az egész ESP-modell alapja. Ha a
 * marketing-forgalom ugyanarról az IP-ről megy, mint a jelszó-visszaállító levél, akkor
 * EGYETLEN kampány spam-jelölése után a tranzakciós levél sem érkezik meg. Ezért a
 * csatorna-egyezés nem finomhangolás, hanem szabály.
 *
 * A választás sorrendje determinisztikus (nem „valamelyik"): a domainhez rendelt pool
 * erősebb az általánosnál, a saját (dedikált) erősebb a megosztottnál.
 */
final class IpPoolSelector
{
    public function __construct(private readonly WarmupSchedule $schedule) {}

    /** Kiválasztja az IP-t — de NEM foglalja le. A lefoglalás a `claim()`. */
    public function select(Message $message, ?IpPool $pool = null): ?IpAddress
    {
        return $this->availableAddresses($message, $pool)[0] ?? null;
    }

    /**
     * Kiválasztás + ATOMI lefoglalás a napi keretből.
     *
     * A feltételes UPDATE azért kell, mert két párhuzamos küldés különben ugyanazt a
     * szabad keretet látná, és együtt lépnék át a warmup limitjét — pont azt, amit véd.
     *
     * Ha egy címet elveszítünk a versenyben, MEGYÜNK TOVÁBB a következőre: enélkül
     * terhelés alatt `NO_SENDING_IP`-vel halasztanánk el a levelet, holott a poolban van
     * szabad keretű IP.
     */
    public function claim(Message $message, ?IpPool $pool = null): ?IpAddress
    {
        $today = now()->toDateString();

        // A dátumot a `now()` adja, nem a hívó — de az interpoláció akkor is csak úgy
        // vállalható, ha a FORMA kikényszerített. (A DB `current_date`-je egyszerűbb lenne,
        // de akkor az idő-ugrásos tesztek nem tudnák mozgatni a napot.)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new RuntimeException('Váratlan dátumformátum az IP-foglalásban: '.$today);
        }

        foreach ($this->availableAddresses($message, $pool) as $address) {
            $claimed = IpAddress::query()
                ->whereKey($address->getKey())
                ->where('status', 'active')
                ->where(fn ($query) => $query
                    ->whereNull('sent_on')
                    ->orWhere('sent_on', '!=', $today)
                    ->orWhereColumn('sent_today', '<', 'daily_limit'))
                ->update([
                    // A számláló DÁTUMHOZ kötött: nap közben nő, új napon magától újraindul.
                    // Kötéssel, nem interpolációval — a nyers SQL a statikus elemzést is
                    // vakká teszi, és egy jövőbeli, paraméterezhető nap injektálható lenne.
                    'sent_today' => DB::raw("case when sent_on = '{$today}' then sent_today + 1 else 1 end"),
                    'sent_on' => $today,
                    'updated_at' => now(),
                ]);

            if ($claimed > 0) {
                return $address->fresh();
            }
        }

        return null;
    }

    /**
     * A szóba jöhető címek — pool-prioritás szerint.
     *
     * @return list<IpAddress>
     */
    private function availableAddresses(Message $message, ?IpPool $pool = null): array
    {
        $pools = $pool !== null ? [$pool] : $this->candidates($message);
        $addresses = [];

        foreach ($pools as $candidate) {
            if (! $candidate->servesChannel((string) $message->channel)) {
                continue;
            }

            foreach ($this->usableIn($candidate) as $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /** @return list<IpPool> */
    private function candidates(Message $message): array
    {
        $pools = IpPool::query()
            ->with(['addresses', 'domains'])
            ->when($message->ip_pool_id !== null, fn ($query) => $query->whereKey($message->ip_pool_id))
            ->get();

        $domainId = $message->domain_id;

        return $pools
            ->sortBy(fn (IpPool $pool) => [
                // 1. a domainhez RENDELT pool, 2. a saját (dedikált), 3. a megosztott
                $domainId !== null && $pool->domains->contains('id', $domainId) ? 0 : 1,
                $pool->workspace_id === null ? 1 : 0,
            ])
            ->values()
            ->all();
    }

    /** @return list<IpAddress> */
    private function usableIn(IpPool $pool): array
    {
        $today = now()->toDateString();

        return $pool->addresses
            ->filter(function (IpAddress $address) use ($today): bool {
                if ($address->status !== 'active') {
                    // A karanténba tett IP akkor sem kap forgalmat, ha ő az EGYETLEN a
                    // poolban: a „legalább küldjünk valamivel" viselkedés pont a beteg
                    // IP-t hajtaná, és azzal a domain reputációját is.
                    return false;
                }

                $used = (string) $address->sent_on?->toDateString() === $today ? (int) $address->sent_today : 0;

                return $used < (int) $address->daily_limit;
            })
            ->values()
            ->all();
    }
}
