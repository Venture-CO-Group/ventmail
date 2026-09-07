<?php

declare(strict_types=1);

namespace App\Modules\Validation\Data;

/**
 * Egy címellenőrzés EREDMÉNYE (39. kritérium).
 *
 * A `risk` és a `classification` NEM ugyanaz, és ez szándékos: a pontszám folytonos (a gate
 * küszöbbel dönt), a minősítés viszont az a négy szó, amit az ügyfél a felületen lát. Egy
 * puszta pontszámból az integrátor nem tudná, mit kezdjen a 0.42-vel.
 */
final readonly class ValidationVerdict
{
    public const DELIVERABLE = 'deliverable';

    public const RISKY = 'risky';

    public const UNDELIVERABLE = 'undeliverable';

    /** Nem tudtuk eldönteni — és ezt KIMONDJUK, nem tippelünk helyette. */
    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $checks  ellenőrzésenkénti részeredmény
     * @param  list<string>  $reasons  strukturált okok — ezekre ágazhat az integráció
     */
    public function __construct(
        public string $email,
        public string $classification,
        public float $risk,
        public array $checks = [],
        public array $reasons = [],
        public ?string $suggestion = null,
        public bool $cached = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'address' => $this->email,
            'result' => $this->classification,
            'risk' => round($this->risk, 2),
            'reasons' => $this->reasons,
            // Az elgépelés-javaslat a legnagyobb üzleti értékű mező: egy `gmial.com` cím a
            // listán örökre bounce-ol, de egy kattintással javítható.
            'did_you_mean' => $this->suggestion,
            'checks' => $this->checks,
            'cached' => $this->cached,
        ];
    }
}
