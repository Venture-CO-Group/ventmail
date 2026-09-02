<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 5. kapu — rate-limit workspace-enként.
 *
 * A kulcs a WORKSPACE, nem az IP: egy ESP-nél az IP-alapú limit használhatatlan (proxy,
 * több szerver, mobilhálózat).
 */
final class RateLimitGate implements Gate
{
    public function name(): string
    {
        return 'rate_limit';
    }

    public function check(Message $message): GateResult
    {
        $key = 'sending:'.$message->workspace_id;
        $limit = (int) config('sending.rate_limit.per_minute');

        // A limit CÍMZETTBEN mér, nem hívásban: különben 300 hívás × 1000 címzett
        // „limiten belül" 300 000 levelet jelentene.
        $needed = max(1, count($message->recipients));
        $used = RateLimiter::attempts($key);

        if ($used + $needed > $limit) {
            return GateResult::reject(
                GateReason::RateLimited,
                sprintf(
                    'Percenkénti limit: %d/%d felhasználva, ez a küldés %d címzett; %d mp múlva oldódik',
                    $used, $limit, $needed, RateLimiter::availableIn($key)
                )
            );
        }

        RateLimiter::increment($key, 60, $needed);

        return GateResult::pass(sprintf('%d/%d', $used + $needed, $limit));
    }
}
