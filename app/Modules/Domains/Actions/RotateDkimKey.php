<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DkimKeyManager;

/**
 * DKIM kulcsrotáció ÁTFEDÉSSEL (M0-4 elfogadási kritérium #27).
 *
 * Az előző selector és kulcspár megmarad, amíg a régi DNS-rekord ki nem fut. Enélkül a
 * rotáció pillanatában minden útban lévő levél aláírása érvénytelenné válna — a fogadó
 * oldalak pedig épp ilyenkor büntetnek a legkeményebben.
 */
final class RotateDkimKey
{
    public function __construct(private readonly DkimKeyManager $dkim) {}

    public function __invoke(Domain $domain): Domain
    {
        $keys = $this->dkim->generate();

        $domain->forceFill([
            'dkim_previous_selector' => $domain->dkim_selector,
            'dkim_previous_public_key' => $domain->dkim_public_key,
            'dkim_previous_private_key' => $domain->dkim_private_key,
            'dkim_selector' => $keys['selector'],
            'dkim_public_key' => $keys['public'],
            'dkim_private_key' => $keys['private'],
            'dkim_rotated_at' => now(),
            // Az új selector DNS-rekordja még nincs kint: a DKIM-státusz visszaáll.
            'dkim_status' => 'pending',
        ])->save();

        return $domain;
    }
}
