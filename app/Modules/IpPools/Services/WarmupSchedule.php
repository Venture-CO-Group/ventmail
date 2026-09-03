<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Services;

/**
 * Warmup-görbe: mennyit szabad küldeni egy FRISS IP-ről a warmup n. napján.
 *
 * Miért nem opcionális: a nagy fogadók (Gmail, Outlook) az ISMERETLEN IP hirtelen
 * volumenét spam-jelként értelmezik. Egy új IP-ről kiengedett 100 000 levél nem
 * „gyorsabb kézbesítés", hanem azonnali blocklist — és onnan hetekig tart visszajönni.
 *
 * A görbe SZÁNDÉKOSAN konzervatív; a warmup végén nincs mesterséges plafon.
 */
final class WarmupSchedule
{
    /** Napi felső korlát a warmup napjai szerint (1-alapú). */
    private const CURVE = [50, 100, 500, 1000, 5000, 10_000, 20_000, 50_000, 100_000, 200_000];

    public function limitForDay(int $day): int
    {
        if ($day < 1) {
            return self::CURVE[0];
        }

        return self::CURVE[min($day, $this->days()) - 1];
    }

    public function days(): int
    {
        return count(self::CURVE);
    }

    public function isComplete(int $day): bool
    {
        return $day > $this->days();
    }
}
