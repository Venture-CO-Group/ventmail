<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IpPools\Models\IpAddress;

/**
 * Reputáció-figyelés és automatikus visszafogás (17. kritérium).
 *
 * A küszöbök a nagy fogadók publikus ajánlásaiból jönnek: a Google Postmaster a 0,3%
 * feletti PANASZ-arányt már kritikusnak jelöli, a 2-5% feletti bounce-arány pedig
 * gyakorlatilag garantált blocklist. A visszafogás ezért NEM riasztás, hanem beavatkozás:
 * a napi limit csökken, és romlás esetén az IP karanténba kerül.
 *
 * Miért automatikus: mire egy ember megnézi a riasztást, a reputáció már odalett — a
 * nagy fogadóknál a visszaszerzés hetekig tart.
 */
final class ReputationMonitor
{
    /** E fölött az IP nem kap több forgalmat. */
    private const QUARANTINE_BOUNCE_RATE = 0.20;

    private const QUARANTINE_COMPLAINT_RATE = 0.01;

    /** E fölött visszafogunk (a limit felére). */
    private const THROTTLE_BOUNCE_RATE = 0.05;

    private const THROTTLE_COMPLAINT_RATE = 0.003;

    public function __construct(private readonly AuditLogger $audit) {}

    public function apply(IpAddress $address, float $bounceRate, float $complaintRate): void
    {
        $score = $this->score($bounceRate, $complaintRate);

        $before = ['score' => (float) $address->reputation_score, 'daily_limit' => (int) $address->daily_limit, 'status' => $address->status];

        if ($bounceRate >= self::QUARANTINE_BOUNCE_RATE || $complaintRate >= self::QUARANTINE_COMPLAINT_RATE) {
            $address->forceFill([
                'reputation_score' => $score,
                'status' => 'quarantined',
                'quarantined_at' => now(),
                'quarantine_reason' => $bounceRate >= self::QUARANTINE_BOUNCE_RATE ? 'bounce_rate' : 'complaint_rate',
            ])->save();

            $this->audit->log('ip.quarantined', null, before: $before, after: [
                'address' => $address->address,
                'bounce_rate' => $bounceRate,
                'complaint_rate' => $complaintRate,
            ]);

            return;
        }

        $limit = (int) $address->daily_limit;

        if ($bounceRate >= self::THROTTLE_BOUNCE_RATE || $complaintRate >= self::THROTTLE_COMPLAINT_RATE) {
            // Visszafogás: felezés, de nem a napi minimum alá.
            $limit = max(50, (int) floor($limit / 2));
        }

        $address->forceFill(['reputation_score' => $score, 'daily_limit' => $limit])->save();
    }

    /** 100-ból indul; a panasz sokkal többet számít, mint a bounce. */
    private function score(float $bounceRate, float $complaintRate): float
    {
        return max(0.0, round(100 - ($bounceRate * 200) - ($complaintRate * 2000), 2));
    }
}
