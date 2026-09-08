<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A küldő IP FELKERÜLT egy feketelistára, vagy LEKERÜLT róla (43. kritérium).
 *
 * Esemény, és nem közvetlen értesítés-küldés, két okból:
 *
 *  1. A CSATORNA nem a monitor dolga. Az ügyeleti riasztás (Slack, PagerDuty, SMS) üzemeltetési
 *     döntés, és a rendszerben ma nincs is ilyen csatorna — a super-admin felület (C2) lesz a
 *     gazdája. Addig ez az esemény a csatlakozási pont, nem egy „majd megírjuk" TODO.
 *  2. E-MAILBEN nem küldhetjük. A riasztás arról szól, hogy a saját küldő IP-nk feketelistán
 *     van; ugyanazon az IP-n kimenő értesítés pontosan oda nem érkezne meg, ahova kellene.
 */
final class BlocklistStateChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $target,
        public readonly string $zone,
        public readonly bool $listed,
        public readonly ?string $detail = null,
    ) {}

    public function message(): string
    {
        return $this->listed
            ? sprintf('A(z) %s IP FELKERÜLT a(z) %s listára: %s', $this->target, $this->zone, (string) $this->detail)
            : sprintf('A(z) %s IP LEKERÜLT a(z) %s listáról.', $this->target, $this->zone);
    }
}
