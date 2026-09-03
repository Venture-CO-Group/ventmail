<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Console;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Services\ReputationMonitor;
use App\Modules\IpPools\Services\WarmupSchedule;
use App\Modules\Sending\Models\Message;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Warmup-görbe léptetése és reputáció-alapú visszafogás (17. kritérium).
 *
 * Enélkül a `WarmupSchedule` és a `ReputationMonitor` olyan kód lenne, amit csak a teszt
 * hív: a napi limit az maradna, amit valaki kézzel beírt az oszlopba, a reputáció-romlás
 * pedig nem járna következménnyel. Mire egy ember észreveszi, a reputáció odalett — a nagy
 * fogadóknál a visszaszerzés hetekig tart.
 *
 * TENANT-FÜGGETLEN: az IP a poolhoz tartozik, a pool lehet platform-szintű.
 */
final class UpdateIpReputationCommand extends Command
{
    protected $signature = 'ip:reputation {--window=24 : hány óra visszamenőleg}';

    protected $description = 'Warmup-limit léptetése és reputáció-alapú visszafogás IP-nként.';

    public function handle(ReputationMonitor $monitor, WarmupSchedule $schedule): int
    {
        $since = now()->subHours((int) $this->option('window'));
        $updated = 0;

        IpAddress::query()->orderBy('id')->chunkById(100, function ($addresses) use ($monitor, $schedule, $since, &$updated): void {
            foreach ($addresses as $address) {
                $this->advanceWarmup($address, $schedule);

                [$bounceRate, $complaintRate] = $this->ratesFor($address, $since);

                $monitor->apply($address->fresh(), $bounceRate, $complaintRate);
                $updated++;
            }
        });

        $this->info("Frissített IP: {$updated}");

        return self::SUCCESS;
    }

    /**
     * A warmup napi kerete a GÖRBÉBŐL jön, nem kézi beállításból.
     *
     * A monitor visszafogása ERŐSEBB: ha az IP-t a reputációja miatt lassítottuk, a görbe
     * nem húzhatja vissza felfelé — ezért csak `active` állapotban léptetünk.
     */
    private function advanceWarmup(IpAddress $address, WarmupSchedule $schedule): void
    {
        if ($address->warmup_started_at === null || $address->status !== 'active') {
            return;
        }

        $day = (int) $address->warmup_started_at->startOfDay()->diffInDays(now()->startOfDay()) + 1;

        if ($schedule->isComplete($day)) {
            $address->forceFill(['warmup_state' => 'warm'])->save();

            return;
        }

        $address->forceFill([
            'warmup_state' => 'warming',
            'daily_limit' => $schedule->limitForDay($day),
        ])->save();
    }

    /** @return array{0: float, 1: float} */
    private function ratesFor(IpAddress $address, DateTimeInterface $since): array
    {
        // Az esemény nem tudja, melyik IP-ről ment — az üzenet igen.
        $uuids = Message::query()
            ->withoutGlobalScopes()
            ->where('ip_address_id', $address->getKey())
            ->where('created_at', '>=', $since)
            ->pluck('uuid');

        if ($uuids->isEmpty()) {
            return [0.0, 0.0];
        }

        $counts = MessageEvent::query()
            ->withoutGlobalScopes()
            ->whereIn('message_uuid', $uuids)
            ->select('type', DB::raw('count(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        $sent = max(1, (int) $counts->get('accepted', 0) + (int) $counts->get('delivered', 0) + (int) $counts->get('bounced', 0));

        return [
            (int) $counts->get('bounced', 0) / $sent,
            (int) $counts->get('complained', 0) / $sent,
        ];
    }
}
