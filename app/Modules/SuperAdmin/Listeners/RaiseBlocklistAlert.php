<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Listeners;

use App\Modules\Deliverability\Events\BlocklistStateChanged;
use App\Modules\SuperAdmin\Services\OnCallAlerts;

/**
 * A BLOCKLIST-RIASZTÁS az ügyeleti csatornára (88. kritérium).
 *
 * ## Ez volt a `PHASE.md`-ben nevesített csatlakozási pont
 *
 * A backlog szó szerint ezt írta: „A `BlocklistStateChanged` esemény a csatlakozási pont;
 * e-mailben nem küldhető (épp a küldő IP-nk van listán). → C2 (super-admin)". Az esemény
 * és a `message()` metódusa megvolt — a HALLGATÓ nem.
 *
 * ## A LEKERÜLÉS is riasztás, nem csak a felkerülés
 *
 * Aki éjjel felkelt egy blocklist miatt, annak tudnia kell, mikor mehet vissza aludni. A
 * „megoldódott" jelzés nélkül az ügyelet a következő reggeli ellenőrzésig nem tudja, hogy
 * a probléma elmúlt — és fölöslegesen eszkalál.
 */
final class RaiseBlocklistAlert
{
    public function __construct(private readonly OnCallAlerts $alerts) {}

    public function handle(BlocklistStateChanged $event): void
    {
        $this->alerts->raise(
            $event->listed ? 'blocklist.listed' : 'blocklist.delisted',
            $event->message(),
            [
                'target' => $event->target,
                'zone' => $event->zone,
                'listed' => $event->listed,
                'detail' => $event->detail,
            ],
        );
    }
}
