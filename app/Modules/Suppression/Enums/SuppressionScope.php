<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Enums;

/**
 * A tiltás hatóköre.
 *
 * `All` a szigorúbb: minden csatornát tilt. A hatókör csak BŐVÜLHET — a szűkítés
 * kinyitná a tranzakciós csatornát egy panaszkodó címre.
 */
enum SuppressionScope: string
{
    case All = 'all';
    case Marketing = 'marketing';

    public function isStricterThan(self $other): bool
    {
        return $this === self::All && $other !== self::All;
    }
}
