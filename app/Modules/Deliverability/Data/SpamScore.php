<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

/** A küldés előtti spam-pontszám és a hozzá tartozó JAVASLATOK. */
final readonly class SpamScore
{
    /** @param list<array<string, mixed>> $findings */
    public function __construct(
        public float $score,
        public array $findings = [],
    ) {}

    /** A küszöb fölött figyelmeztetünk — de NEM blokkolunk: a küldés joga a tenanté. */
    public function warns(): bool
    {
        return $this->score >= (float) config('deliverability.spam_score.warn_at');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'warns' => $this->warns(),
            'threshold' => (float) config('deliverability.spam_score.warn_at'),
            'findings' => $this->findings,
        ];
    }
}
