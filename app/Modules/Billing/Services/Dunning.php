<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Contracts\VentPayClient;
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
    public function __construct(private readonly VentPayClient $ventPay) {}

    /** A határidő után ennyi nappal lesz `overdue`. */
    public const OVERDUE_AFTER_DAYS = 7;

    /** A határidő után ennyi nappal lesz `suspended`. */
    public const SUSPEND_AFTER_DAYS = 30;

    /**
     * AZ ÁLLAPOTGÉP LÉPTETÉSE — a ventPay SZAVÁVAL, nem csak a mi óránkkal.
     *
     * ## A MÉRT KIESÉS, amit ez a metódus okozott
     *
     * Az állapotgép eredetileg kizárólag a `due_at`-ből léptetett. A security-review
     * végigmérte egy NULLA fogyású, teljesen rendes tenanton:
     *
     *     +16 nap → due · +24 nap → overdue · +46 nap → suspended
     *     és a tranzakciós jelszó-visszaállító levél: `rejected / QUOTA_EXCEEDED`
     *
     * A `markPaid()`-nek NULLA produkciós hívója volt, tehát az állapotgépnek volt bemenete
     * és nem volt kimenete: ~45 nappal az első zárás után a platform ÖNMAGÁT állította le,
     * és a helyreállítás csak kézi `UPDATE`-tel lett volna lehetséges. Ez nem
     * tenant-támadás, hanem ütemezett, platform-szintű kiesés.
     *
     * ## A JAVÍTÁS: a fizetés ténye KÉRDÉS, nem feltevés
     *
     * Minden léptetés előtt megkérdezzük a ventPay-t, hogy a periódus rendezett-e
     * (`isPeriodSettled`). Ha igen, a kivonat `paid`-re zárul, és SOHA nem lép a
     * felfüggesztés felé. A `FakeVentPayClient` mindent rendezettnek jelent, tehát amíg a
     * ventPay nem él, senkit nem függesztünk fel egy olyan tartozás miatt, amiről nem
     * tudunk.
     *
     * @return array<string, int> melyik állapotba hány kivonat lépett
     */
    public function advance(): array
    {
        $moved = ['paid' => 0, 'due' => 0, 'overdue' => 0, 'suspended' => 0];

        /*
         | ELŐBB A RENDEZÉS: aki fizetett, kilép az állapotgépből.
         |
         | Ha ez a léptetés UTÁN lenne, egy rendezett kivonat egy futásban még
         | továbbléphetne (`due → overdue`), és a felület egy pillanatra „késettként"
         | mutatna valakit, aki már fizetett.
         */
        $moved['paid'] = $this->settle();

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
     * A RENDEZETT periódusok lezárása — a ventPay szava alapján.
     *
     * Csak a NYITOTT állapotokat kérdezi: a `paid` már kilépett, és egy `paid → paid`
     * léptetés fölösleges ventPay-hívás lenne minden futásban, minden lezárt periódusra.
     */
    private function settle(): int
    {
        $settled = 0;

        Invoice::query()
            ->withoutGlobalScopes()
            ->whereIn('dunning_state', ['open', 'due', 'overdue', 'suspended'])
            ->orderBy('id')
            // CHUNKOLVA: a `advance()` naponta fut, és minden nyitott kivonatra egy
            // ventPay-hívás jut — egy korlátlan `get()` egy nagy platformon a memóriát
            // vinné el, és a hívások sem lennének megszakíthatók.
            ->chunkById(200, function ($invoices) use (&$settled): void {
                foreach ($invoices as $invoice) {
                    if ($this->ventPay->isPeriodSettled((int) $invoice->workspace_id, (string) $invoice->period)) {
                        $this->markPaid($invoice);
                        $settled++;
                    }
                }
            });

        return $settled;
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
        /*
         | A PARENT ÉS A SAJÁT kivonata is számít — mindkettő, `or`-ral.
         |
         | A review megmérte, hogy a puszta parent-kérdés a `workspace_parents` EGYETLEN
         | sorával ki-be kapcsolható:
         |
         |     isSuspended(felfüggesztett tenant) = true
         |     → parent-kapcsolat felvéve egy TISZTA parent alá
         |     → isSuspended = false, és a küldés `delivered` lett
         |
         | A packet kommentje csak az egyik irányt védte („alszámla létrehozásával
         | megkerülhető") — a fordított irány, a felfüggesztett fiók BEKÖLTÖZÉSE egy tiszta
         | parent alá, nyitva volt. És a kapcsolat TÖRLÉSE is feloldotta a felfüggesztést.
         |
         | Az `or` mindkettőt lezárja: a felfüggesztés nem szűnik meg attól, hogy a
         | hierarchia változik.
         */
        $ids = [(int) $workspace->getKey()];

        $parent = $workspace->parents()->first();

        if ($parent !== null) {
            $ids[] = (int) $parent->getKey();
        }

        return Invoice::query()
            ->withoutGlobalScopes()
            ->whereIn('workspace_id', $ids)
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

    /**
     * EGY ÁLLAPOT-LÉPTETÉS — auditálva.
     *
     * ## Miért kell KÜLÖN audit, ha a `Invoice` `Auditable`
     *
     * Mert ez tömeges `update()`, ami NEM tüzel modell-eventet — tehát az `Auditable`
     * trait nem fut le. A review megmérte: egy `open → due` léptetés után az audit-sorok
     * száma VÁLTOZATLAN maradt. A felfüggesztés a legdurvább eszköz (a tranzakciós levelet
     * is megállítja), és a golden rule 11 szerint minden admin-művelet auditált — pont
     * ennek kell nyomot hagynia.
     *
     * SORONKÉNTI audit helyett ÖSSZESÍTETT: egy platform-szintű léptetés több ezer
     * kivonatot érint, és tízezer audit-sor egy ütemezett futásról nem nyom, hanem zaj. A
     * darabszám és a két állapot az, amiből egy vizsgálat visszakeresi az érintetteket.
     */
    private function transition(string $from, string $to, int $afterDays): int
    {
        $moved = $this->applyTransition($from, $to, $afterDays);

        if ($moved > 0) {
            app(AuditLogger::class)->log(
                'billing.dunning_transition',
                context: [
                    'from' => $from,
                    'to' => $to,
                    'after_days' => $afterDays,
                    'invoices' => $moved,
                ],
            );
        }

        return $moved;
    }

    private function applyTransition(string $from, string $to, int $afterDays): int
    {
        return Invoice::query()
            ->withoutGlobalScopes()
            ->where('dunning_state', $from)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->subDays($afterDays))
            ->update(array_merge(
                ['dunning_state' => $to, 'updated_at' => now()],
                // A `suspended_at` CSAK a felfüggesztési lábon íródik.
                //
                // Korábban a többi láb `null`-ra állította, és ezzel kitörölte azt az
                // előzményt, amit a `markPaid()` docblockja szándékosan megőriz („a
                // felfüggesztés TÉNYE előzmény, amit egy későbbi vizsgálatnál — miért nem
                // ment ki egy levél márciusban? — tudni kell").
                $to === 'suspended' ? ['suspended_at' => now()] : [],
            ));
    }
}
