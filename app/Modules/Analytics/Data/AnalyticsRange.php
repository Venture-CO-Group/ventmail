<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Illuminate\Support\Carbon;

/**
 * Az analitika IDŐSZAKA (v1.1 · 8. modul): `7` | `30` | `90` nap, vagy `custom` (`from`–`to`,
 * zárt, a felhasználó időzónájának NAPJAI). Legfeljebb 366 nap, a vége legfeljebb ma. Érvénytelen
 * bemenet → a 30 napos alapértelmezés (a GET-oldal nem hibázik egy elgépelt URL-en).
 */
final class AnalyticsRange
{
    public const PRESETS = ['7', '30', '90'];

    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $timezone,
    ) {}

    public static function resolve(?string $range, ?string $from, ?string $to, string $timezone): self
    {
        $today = Carbon::now($timezone)->startOfDay();

        if ($range === 'custom' && ($custom = self::custom($from, $to, $timezone, $today)) !== null) {
            return $custom;
        }

        $days = in_array((string) $range, self::PRESETS, true) ? (int) $range : 30;

        return new self((string) $days, $today->copy()->subDays($days - 1), $today, $timezone);
    }

    /** A kérés paramétereinek érvényessége (a riport-kérés 422-t ad, a képernyő visszaesik). */
    public static function validCustom(?string $from, ?string $to, string $timezone): bool
    {
        return self::custom($from, $to, $timezone, Carbon::now($timezone)->startOfDay()) !== null;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** @return array{range: string, from: string, to: string, days: int, timezone: string} */
    public function toArray(): array
    {
        return [
            'range' => $this->key,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'timezone' => $this->timezone,
        ];
    }

    private static function custom(?string $from, ?string $to, string $timezone, Carbon $today): ?self
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';

        if (! is_string($from) || ! is_string($to) || preg_match($pattern, $from) !== 1 || preg_match($pattern, $to) !== 1) {
            return null;
        }

        try {
            $start = Carbon::createFromFormat('!Y-m-d', $from, $timezone);
            $end = Carbon::createFromFormat('!Y-m-d', $to, $timezone);
        } catch (\Throwable) {
            return null;
        }

        if ($start === null || $end === null || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            return null;
        }

        if ($end->gt($today) || $end->lt($start) || $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            return null;
        }

        return new self('custom', $start, $end, $timezone);
    }
}
