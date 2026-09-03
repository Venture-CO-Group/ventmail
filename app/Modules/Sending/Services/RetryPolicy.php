<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

/**
 * Visszalépés a halasztott (4xx) kézbesítésnél (19. kritérium).
 *
 * Exponenciális, felső korláttal. A korlát nélkül a sor sosem ürülne ki; a korlátozatlan
 * ÚJRAPRÓBÁLÁS nélkül viszont egy greylistelt levél SOSEM érkezne meg — a greylist épp
 * arra épül, hogy a legitim küldő újrapróbál.
 */
final class RetryPolicy
{
    private const BASE_SECONDS = 60;

    private const MAX_SECONDS = 21_600; // 6 óra

    private const MAX_ATTEMPTS = 8;

    public function delaySeconds(int $attempt): int
    {
        $delay = self::BASE_SECONDS * (2 ** max(0, $attempt - 1));

        return (int) min($delay, self::MAX_SECONDS);
    }

    public function maxDelaySeconds(): int
    {
        return self::MAX_SECONDS;
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public function shouldGiveUp(int $attempt): bool
    {
        return $attempt > self::MAX_ATTEMPTS;
    }
}
