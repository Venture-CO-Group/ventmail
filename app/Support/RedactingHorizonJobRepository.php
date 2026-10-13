<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Horizon\JobPayload;
use Laravel\Horizon\Repositories\RedisJobRepository;

/**
 * A HORIZON failed-job tárolójának (Redis) redakciója: a `failed()` a kivétel TELJES szövegét
 * (`(string) $exception`) menti — QueryException esetén a kötésekkel és az értékekkel együtt.
 * Ugyanaz a redakció, mint a `failed_jobs` dekorátoré.
 */
final class RedactingHorizonJobRepository extends RedisJobRepository
{
    public function failed($exception, $connection, $queue, JobPayload $payload)
    {
        parent::failed(RedactQueryException::throwable($exception), $connection, $queue, $payload);
    }
}
