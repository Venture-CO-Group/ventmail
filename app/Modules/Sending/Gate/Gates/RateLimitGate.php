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

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return GateResult::reject(
                GateReason::RateLimited,
                sprintf('Percenkénti limit (%d) elérve, %d mp múlva oldódik', $limit, RateLimiter::availableIn($key))
            );
        }

        RateLimiter::hit($key, 60);

        return GateResult::pass();
    }
}
