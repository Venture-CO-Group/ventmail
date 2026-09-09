<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Billing\Services\Dunning;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * 4. kapu — entitlement és kvóta (ventPay).
 *
 * A kvótát a ventPay tartja (egy képesség — egy gazda); a ventMail nem számol saját
 * egyenleget. Amíg a ventPay nem él, a fake adja a kontraktust. A kérdést az
 * `EntitlementChecker` teszi fel: ő ismeri a SZÁMLÁZOTT workspace-t (sub-accountnál a
 * parentet) és a platform-admin plan-override-ját (C2 · 83. kritérium).
 *
 * KÉT dolgot néz, ebben a sorrendben: a fiók FELFÜGGESZTÉSÉT (C1 · dunning), majd a
 * kvótát. A felfüggesztés előbb, mert az a súlyosabb állapot — és mert egy felfüggesztett
 * fióknál a kvóta kérdése értelmetlen.
 */
final class EntitlementGate implements Gate
{
    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly Dunning $dunning,
    ) {}

    public function name(): string
    {
        return 'entitlement';
    }

    public function check(Message $message): GateResult
    {
        /*
         | A FELFÜGGESZTÉS ELŐBB, a kvóta ELŐTT (C1 · 79. kritérium).
         |
         | Ha ez csak a felületen látszana, a nem fizető tenant a MI IP-nkről, a MI
         | reputációnkkal küldene tovább. A `QUOTA_EXCEEDED` okot adjuk vissza: a hívó
         | szempontjából ez ugyanaz a művelet (rendezni kell a fiókot), és nem
         | szivárogtatunk pénzügyi állapotot egy API-válaszba.
         |
         | A sub-account a PARENT állapotát viszi — különben a felfüggesztés egy alszámla
         | létrehozásával megkerülhető lenne.
         */
        /*
         | A `withTrashed()` NEM opcionális, és a `null` FAIL-CLOSED.
         |
         | A `Workspace` soft-deletes-es, tehát a sima `belongsTo` reláció egy törölt
         | tenantnál `null`-t ad — a review megmérte, hogy ilyenkor a felfüggesztés-ág
         | CSENDBEN átengedte a küldést: felfüggesztett + soft-deleted workspace →
         | `status = delivered`. Pénzügyi kapunál a „nem tudom" nem lehet „engedem".
         */
        $workspace = $message->workspace()->withTrashed()->first();

        if ($workspace === null) {
            return GateResult::reject(
                GateReason::GateError,
                'A workspace nem feloldható — a számlázási állapot nem ellenőrizhető.'
            );
        }

        if ($this->dunning->isSuspended($workspace)) {
            return GateResult::reject(
                GateReason::QuotaExceeded,
                'A fiók fizetés miatt felfüggesztve — a rendezés azonnal visszaadja a küldés jogát.'
            );
        }

        $needed = max(1, count($message->recipients));

        /*
         | A KVÓTA az `EntitlementChecker`-en megy, nem közvetlenül a ventPay-en.
         |
         | Két hibát javít egyszerre, és mindkettőt mérte a review:
         |
         | 1. A PLAN-OVERRIDE (C2 · 83. kritérium) eddig NEM ért el idáig. A támogatás
         |    megemelte a kvótát, auditálva, a felület azt mondta „megvan" — a levél mégis
         |    `QUOTA_EXCEEDED`-del elakadt (mérve: override 500 000, ventPay 0, kapu tilt).
         |    Az `EntitlementChecker::remainingQuota()` addig hívó nélküli halott kód volt:
         |    a képesség létezett, de a valódi úton semmi nem használta.
         |
         | 2. A SUB-ACCOUNT a PARENT kvótáját használja. A nyers `$message->workspace_id`
         |    a CHILD-ot kérdezte, a számla viszont a parenté — egy alszámla így vagy
         |    nulla kvótát látott, vagy egy másik keretet, mint amiért fizetnek.
         |
         | A `$workspace` itt már fel van oldva (`withTrashed()`), tehát nincs újabb
         | lekérdezés.
         */
        $remaining = $this->entitlements->remainingQuota($workspace);

        if ($remaining < $needed) {
            return GateResult::reject(
                GateReason::QuotaExceeded,
                "Hátralévő kvóta: {$remaining}, szükséges: {$needed}"
            );
        }

        return GateResult::pass("kvóta: {$remaining}");
    }
}
