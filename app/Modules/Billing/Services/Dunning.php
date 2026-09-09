<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Tenancy\Models\Workspace;

/**
 * DUNNING (79. kritérium): `open → due → overdue → suspended`, és a rendezés `paid`.
 *
 * ## Ez TÜKÖR, nem döntés
 *
 * A ventPay dönti el, hogy valaki fizetett-e — a ventMail csak azt tudja, hogy emiatt
 * küldhet-e. Ezért nincs itt ár, nincs fizetési kísérlet, nincs kártya: a `markPaid()`-et
 * a ventPay webhookja (illetve a C1-ben a szimuláció) hívja, az állapotgép pedig az
 * IDŐALAPÚ léptetést végzi.
 *
 * ## A `suspended` a PRESENDGATE-en fog, nem a felületen
 *
 * Ha csak a riport-oldalon látszana, a küldés tovább menne — és a nem fizető tenant a MI
 * IP-nkről, a MI reputációnkkal küldene. A `EntitlementGate` ezért kérdezi meg, és a
 * `QUOTA_EXCEEDED` okot adja: a hívó szempontjából ez ugyanaz a művelet (rendezni kell a
 * fiókot), és nem szivárogtatunk pénzügyi állapotot egy API-válaszba.
 *
 * ## A TÜRELMI IDŐK, és miért ilyen hosszúak
 *
 * A felfüggesztés a legdurvább eszköz: egy tranzakciós levelet (jelszó-visszaállítás,
 * számla) is megállít, tehát a tenant ÜGYFELEIT sújtja, nem a tenantot. Ezért a
 * `suspended` csak a határidő után 30 nappal jön — addig a `due` és az `overdue` a
 * figyelmeztetés ideje.
 */
final class Dunning
{
    /** A határidő után ennyi nappal lesz `overdue`. */
    public const OVERDUE_AFTER_DAYS = 7;

    /** A határidő után ennyi nappal lesz `suspended`. */
    public const SUSPEND_AFTER_DAYS = 30;

    /**
     * AZ ÁLLAPOTGÉP LÉPTETÉSE — időalapon, minden nyitott kivonatra.
     *
     * @return array<string, int> melyik állapotba hány kivonat lépett
     */
    public function advance(): array
    {
        $moved = ['due' => 0, 'overdue' => 0, 'suspended' => 0];

        /*
         | A LÉPTETÉS SORRENDJE HÁTULRÓL ELŐRE.
         |
         | Előrefelé haladva egy kivonat EGY futásban `open → due → overdue → suspended`-ig
         | jutna (mindegyik feltétel igaz egy 40 napos késésnél), és a figyelmeztetési
         | szakaszok kimaradnának — a tenant a `due` értesítést meg sem kapná. Hátulról
         | előre minden futás LEGFELJEBB EGY állapotot léptet.
         */
        $moved['suspended'] = $this->transition('overdue', 'suspended', self::SUSPEND_AFTER_DAYS);
        $moved['overdue'] = $this->transition('due', 'overdue', self::OVERDUE_AFTER_DAYS);
        $moved['due'] = $this->transition('open', 'due', 0);

        return $moved;
    }

    /**
     * FEL VAN-E FÜGGESZTVE ez a workspace?
     *
     * A SUB-ACCOUNT a parent állapotát viszi: a parent fizet, tehát ha a parent fel van
     * függesztve, a child sem küldhet — különben a felfüggesztés egy alszámla
     * létrehozásával megkerülhető lenne.
     */
    public function isSuspended(Workspace $workspace): bool
    {
        $billable = (int) ($workspace->parents()->first()?->getKey() ?? $workspace->getKey());

        return Invoice::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $billable)
            ->where('dunning_state', 'suspended')
            ->exists();
    }

    /**
     * RENDEZVE — a ventPay szava.
     *
     * A küldés joga AZONNAL visszaáll, nem a következő zárási ciklusban: a felfüggesztés
     * védelem, nem büntetés.
     */
    public function markPaid(Invoice $invoice): Invoice
    {
        $invoice->forceFill([
            'dunning_state' => 'paid',
            'paid_at' => now(),
            // A `suspended_at` MEGMARAD: a felfüggesztés TÉNYE előzmény, amit egy későbbi
            // vizsgálatnál (miért nem ment ki egy levél márciusban?) tudni kell.
        ])->save();

        return $invoice->fresh();
    }

    private function transition(string $from, string $to, int $afterDays): int
    {
        return Invoice::query()
            ->withoutGlobalScopes()
            ->where('dunning_state', $from)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->subDays($afterDays))
            ->update([
                'dunning_state' => $to,
                'suspended_at' => $to === 'suspended' ? now() : null,
                'updated_at' => now(),
            ]);
    }
}
