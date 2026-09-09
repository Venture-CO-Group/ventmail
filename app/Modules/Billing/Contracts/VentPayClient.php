<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

/**
 * ventPay — entitlement és kvóta (a PreSendGate 4. kapuja).
 *
 * Kártyaadatot a ventMail SOHA nem lát. Amíg a ventPay nem él, a `FakeVentPayClient` a
 * teszttel fedett kontraktus; élesedéskor CSAK a config vált (golden rule: egy képesség —
 * egy gazda).
 */
interface VentPayClient
{
    /** Hány üzenet küldhető még a jelenlegi elszámolási időszakban. */
    public function remainingSendQuota(int $workspaceId): int;

    public function hasEntitlement(int $workspaceId, string $feature): bool;

    /**
     * RENDEZETT-e ez a periódus? — a ventPay szava, nem a mi óránk.
     *
     * ## Miért KELL ez a metódus, és mit mértünk nélküle
     *
     * A dunning-állapotgép eredetileg kizárólag a MI óránkból léptetett: a zárás minden
     * kivonatra `due_at = now() + 15 nap`-ot írt, és 45 nap után a `suspended` állapot
     * MINDEN tenantra beállt — fogyástól függetlenül, fizetési információ nélkül. A
     * security-review ezt végigmérte egy nulla fogyású, rendes tenanton:
     *
     *     +16 nap → due · +24 nap → overdue · +46 nap → suspended
     *     és a tranzakciós jelszó-visszaállító levél: `rejected / QUOTA_EXCEEDED`
     *
     * A `markPaid()`-nek NULLA produkciós hívója volt (se webhook, se parancs, se route),
     * tehát az állapotgépnek volt bemenete és nem volt kimenete: ~45 nappal az első zárás
     * után a platform önmagát állította volna le, és a helyreállítás csak kézi
     * `UPDATE`-tel lett volna lehetséges.
     *
     * A fizetés TÉNYE nem a ventMail tudása — ezért kérdés, nem feltevés. A `FakeVentPayClient`
     * RENDEZETTNEK jelent mindent: amíg a ventPay nem él, senkit nem függesztünk fel egy
     * olyan tartozás miatt, amiről nem tudunk.
     *
     * @param  string  $period  `YYYY-MM`
     */
    public function isPeriodSettled(int $workspaceId, string $period): bool;
}
