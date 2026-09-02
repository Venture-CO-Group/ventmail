<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DkimKeyManager;
use RuntimeException;

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
        // Kettős rotáció az átfedésen belül MEGSEMMISÍTENÉ a DNS-ben még aktuális előző
        // kulcsot: a második rotáció felülírná a `dkim_previous_*` mezőket.
        if ($domain->previousDkimIsValid()) {
            throw new RuntimeException(
                'Az előző kulcs átfedési ideje még tart — előbb publikáld az új selectort, '
                .'vagy várd meg az átfedés lejártát.'
            );
        }

        $keys = $this->dkim->generate();

        $domain->forceFill([
            'dkim_previous_selector' => $domain->dkim_selector,
            'dkim_previous_public_key' => $domain->dkim_public_key,
            'dkim_previous_private_key' => $domain->dkim_private_key,
            'dkim_selector' => $keys['selector'],
            'dkim_public_key' => $keys['public'],
            'dkim_private_key' => $keys['private'],
            'dkim_rotated_at' => now(),
            // AZ ALÁÍRÁS MARAD A RÉGI KULCCSAL, amíg az új rekord meg nem jelenik a DNS-ben:
            // különben a rotáció pillanatától a terjedés végéig MINDEN levél DKIM-je bukna.
            'dkim_active_selector' => $domain->dkim_selector,
            // Az új selector DNS-rekordja még nincs kint: a DKIM-státusz visszaáll.
            'dkim_status' => 'pending',
        ])->save();

        return $domain;
    }
}
