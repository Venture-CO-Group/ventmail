<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

use App\Modules\VentSec\Support\VsesCatalog;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Egy VSES 1.0 esemény összeállítása a ventSec producer-sémája szerint
 * (`resources/vses/event.schema.json`): zárt mezőkészlet, ULID `event_id`, ms-pontos UTC
 * `occurred_at`, a katalógus kategóriája/súlyossága, rendszer-aktor (nincs `sub`, nincs
 * `network`, nincs `target`). A kontextus a katalógus ZÁRT allowlistje; érték: string, bool,
 * null, egész (±(2^53−1)) vagy ezek listája — lebegőpontos szám nincs.
 */
final class EventFactory
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function make(string $action, string $outcome, array $context = []): array
    {
        $entry = VsesCatalog::entry($action)
            ?? throw new InvalidArgumentException("Ismeretlen VSES action: {$action}");

        if (! in_array($outcome, VsesCatalog::OUTCOMES, true)) {
            throw new InvalidArgumentException("Érvénytelen VSES outcome: {$outcome}");
        }

        foreach ($context as $key => $value) {
            if (! in_array($key, $entry['context'], true)) {
                throw new InvalidArgumentException("A(z) {$action} eseményben nem engedélyezett kontextus-kulcs: {$key}");
            }

            if (! $this->valid($value) && ! (is_array($value) && array_is_list($value) && array_filter($value, fn ($v) => ! $this->valid($v)) === [])) {
                throw new InvalidArgumentException("A(z) {$key} kontextus-érték nem engedélyezett típusú (string, bool, null, egész).");
            }
        }

        return [
            'vses' => '1.0',
            'event_id' => (string) Str::ulid(),
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'source' => [
                'app' => 'ventmail',
                'env' => $this->environment(),
                'instance' => (string) (config('ventsec.events.instance') ?: gethostname() ?: 'ventmail'),
                'version' => (string) config('app.version', 'dev'),
            ],
            'category' => $entry['category'],
            'action' => $action,
            'outcome' => $outcome,
            'severity_hint' => $entry['severity_hint'],
            'actor' => ['type' => 'system'],
            'context' => $context,
        ];
    }

    private function valid(mixed $value): bool
    {
        return $value === null || is_string($value) || is_bool($value)
            || (is_int($value) && abs($value) <= self::MAX_SAFE_INTEGER);
    }

    private function environment(): string
    {
        return match ((string) app()->environment()) {
            'production' => 'prod',
            'staging' => 'staging',
            'testing' => 'test',
            default => 'dev',
        };
    }
}
