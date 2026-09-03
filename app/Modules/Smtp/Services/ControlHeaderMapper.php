<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Services;

use App\Modules\Smtp\Data\SubmissionControls;
use Carbon\Carbon;
use Throwable;

/**
 * `X-VentMail-*` vezérlő fejlécek → az A1 `o:` / `v:` szemantikája (15. kritérium).
 *
 * A vezérlő fejléceket LEVÁGJUK a levélről. Bent hagyva két baj lenne: (a) a
 * `HeaderSafetyGate` átengedi őket (`x-` prefix), tehát kimennének a címzetthez —
 * megmutatva a belső címkéinket és változóinkat; (b) egy továbbküldött levélben a vezérlés
 * MÁSODSZOR is érvényesülne.
 *
 * A szabályok azonosak a REST-oldallal (A1): ami ott 400, az itt `550` — nem csendes
 * eldobás. Aki `X-VentMail-Testmode`-dal migrál, ne küldjön véletlenül valódi levelet.
 */
final class ControlHeaderMapper
{
    private const MAX_TAGS = 3;

    private const MAX_SCHEDULE_DAYS = 7;

    /** @param array<string, string> $headers a MIME-ból megőrzött `X-` fejlécek */
    public function extract(array $headers): SubmissionControls
    {
        $controls = new SubmissionControls;

        foreach ($headers as $name => $value) {
            $lower = strtolower($name);

            if (! str_starts_with($lower, 'x-ventmail-')) {
                // Minden MÁS `X-` fejléc a feladóé: megy tovább a levéllel.
                $controls->headers[$name] = $value;

                continue;
            }

            match ($lower) {
                'x-ventmail-tag' => $this->tags($controls, $value),
                'x-ventmail-variables' => $this->variables($controls, $value),
                'x-ventmail-deliverytime' => $this->deliverytime($controls, $value),
                'x-ventmail-testmode' => $controls->testmode = $this->truthy($value),
                // Ismeretlen vezérlő: elutasítás, nem csendes eldobás — a hívó azt hinné,
                // hogy érvényesült.
                default => $controls->error ??= 'Unknown control header: '.$name,
            };
        }

        return $controls;
    }

    private function tags(SubmissionControls $controls, string $value): void
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', $value))));

        if (count($tags) > self::MAX_TAGS) {
            $controls->error ??= sprintf('At most %d tags allowed', self::MAX_TAGS);

            return;
        }

        $controls->tags = $tags;
    }

    private function variables(SubmissionControls $controls, string $value): void
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            $controls->error ??= 'X-VentMail-Variables must be a JSON object';

            return;
        }

        $controls->variables = $decoded;
    }

    private function deliverytime(SubmissionControls $controls, string $value): void
    {
        try {
            $when = Carbon::parse($value);
        } catch (Throwable) {
            $controls->error ??= 'X-VentMail-Deliverytime must be an RFC 2822 date';

            return;
        }

        if ($when->isPast()) {
            $controls->error ??= 'X-VentMail-Deliverytime is in the past';

            return;
        }

        if ($when->gt(now()->addDays(self::MAX_SCHEDULE_DAYS))) {
            $controls->error ??= sprintf('X-VentMail-Deliverytime is more than %d days ahead', self::MAX_SCHEDULE_DAYS);

            return;
        }

        $controls->deliverytime = $when;
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['yes', 'true', '1', 'on'], true);
    }
}
