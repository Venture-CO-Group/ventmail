<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use RuntimeException;

/** A publikálandó gráf hibás — a hibák listája a felületnek megy. */
final class JourneyGraphInvalid extends RuntimeException
{
    /** @param list<array{code: string, severity: string, node?: string, field?: string}> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('A journey gráfja hibás, nem publikálható.');
    }
}
