<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HAVI ZÁRÁS (77., 78. kritérium).
 *
 * ## Az IDEMPOTENCIA az egyedi indexen áll
 *
 * A zárás ütemezett parancsból fut, és egy ütemező-újraindítás ugyanazt a periódust
 * kétszer is megpróbálhatja. A `invoices` egyedi indexe `(workspace_id, period)` — tehát a
 * második futás nem hoz létre második kivonatot, hanem a MEGLÉVŐT adja vissza. Ez nem
 * „kényelmi" döntés: két kivonat egy periódusra kétszeres számlát jelentene.
 *
 * ## A KIVONAT TÖRTÉNELMI dokumentum
 *
 * A fogyás értékeit a zárás PILLANATÁBAN másolja a `usage` jsonb-be, és a fogyás-sorokat
 * lezárja. Innentől a kivonat akkor is ugyanazt mondja, ha a fogyás-táblát később
 * bármi érintené — és a `usage_records_closed_guard` trigger azt is megakadályozza.
 *
 * ## A KONSZOLIDÁLÁS: a child fogyása a parent számláján, TÉTELESEN
 *
 * A sub-account fogyása a parenthez tartozik (a parent fizet), de a child SAJÁT kivonata
 * is elkészül, `parent_workspace_id`-vel megjelölve. Így:
 *
 * - a per-child bontás visszakereshető (77. kritérium),
 * - a child fogyása NEM duplikálódik: a parent összesítője a child SORAIRA hivatkozik,
 *   nem másolja az értékeket egy harmadik helyre,
 * - és a child kivonata nem „számla": a `parent_workspace_id` jelöli, hogy nem ő fizeti.
 */
final class MonthlyClose
{
    public function __construct(private readonly Metering $metering) {}

    /**
     * EGY workspace periódusának lezárása.
     *
     * A `$parent` explicit: a hívó (a konszolidáló zárás) tudja, hogy sub-accountot zár.
     */
    public function close(Workspace $workspace, string $period, ?Workspace $parent = null): Invoice
    {
        // A KONTEXTUS a zárt workspace: a hívó (a parancs, illetve a
        // `closeConsolidated()`) állítja be, és a szűrést a global scope adja.
        $existing = Invoice::query()->where('period', $period)->first();

        if ($existing !== null) {
            // MÁR LE VAN ZÁRVA: ugyanazt adjuk vissza. Lásd az idempotencia-indoklást.
            return $existing;
        }

        $usage = $this->metering->forPeriod($period);

        return DB::transaction(function () use ($workspace, $period, $parent): Invoice {
            /*
             | A FOGYÁS-SOROK LEZÁRÁSA a kivonat ELŐTT.
             |
             | Ebben a sorrendben egy közben beeső küldés vagy a lezárt sorra fut (és a
             | `Metering` kihagyja), vagy még a lezárás előtt beszámít — a kivonat pedig
             | UTÁNA készül a végleges értékekből. A fordított sorrendben egy közbeeső
             | inkrementum a kivonat és a fogyás-tábla közé esne: a kivonatban nem
             | lenne benne, a következő periódusban sem — vagyis ELVESZNE.
             */
            UsageRecord::query()
                ->where('period', $period)
                ->whereNull('closed_at')
                ->update(['closed_at' => now(), 'updated_at' => now()]);

            // A LEZÁRÁS UTÁNI állapot: ez megy a kivonatba.
            $final = $this->metering->forPeriod($period);

            try {
                $invoice = new Invoice([
                    'parent_workspace_id' => $parent?->getKey(),
                    'period' => $period,
                    'usage' => $final,
                    'dunning_state' => 'open',
                    // A FIZETÉSI HATÁRIDŐ a periódus utáni 15. nap: a `due` állapotba
                    // ekkor lép a dunning-állapotgép.
                    'due_at' => now()->addDays(15),
                    'closed_at' => now(),
                ]);

                $invoice->workspace_id = $workspace->getKey();
                $invoice->save();

                return $invoice;
            } catch (UniqueConstraintViolationException) {
                /*
                 | EGY PÁRHUZAMOS zárás megelőzött — az egyedi index fogta meg.
                 |
                 | Ez nem hiba, hanem a védelem működése: a másik futás kivonata érvényes,
                 | azt adjuk vissza. Az adatbázis dönt, nem egy alkalmazás-szintű
                 | „megnéztem, nincs" ellenőrzés, ami két kérés között befér.
                 */
                return Invoice::query()->where('period', $period)->firstOrFail();
            }
        });
    }

    /**
     * EGY PARENT és MINDEN sub-accountja lezárása — konszolidáltan (77. kritérium).
     *
     * @return list<Invoice> a parent kivonata ELŐL, utána a childeké
     */
    public function closeConsolidated(Workspace $parent, string $period): array
    {
        /*
         | A KONTEXTUS-VÁLTÁS a Tenancy szolgáltatásán megy, nem scope-kikapcsolással.
         |
         | A child zárása a child fogyását olvassa és a child kivonatát írja — tehát a
         | child kontextusában kell futnia. A `runFor()` a Tenancy SAJÁT mechanizmusa erre,
         | és a végén VISSZAADJA a korábbi kontextust: egy `forget()` itt a hívó (a
         | zárás-parancs) workspace-ciklusát vinné el.
         */
        $context = app(WorkspaceContext::class);

        $invoices = [$context->runFor($parent, fn (): Invoice => $this->close($parent, $period))];

        foreach ($parent->children()->get() as $child) {
            $invoices[] = $context->runFor($child, fn (): Invoice => $this->close($child, $period, $parent));
        }

        return $invoices;
    }

    /**
     * A KONSZOLIDÁLT ÖSSZESÍTŐ: a parent és a childjei fogyása, PER-CHILD bontással.
     *
     * A `total` a parent SZÁMLÁJA (ő fizet), a `children` pedig a tételes bontás. Az
     * összeg a child SOROKBÓL áll össze, nem egy harmadik helyre másolt értékből — így nem
     * tud elcsúszni tőlük.
     *
     * @return array{period: string, total: array<string, int>, children: list<array{workspace: string, workspace_uuid: string, usage: array<string, int>}>}
     */
    public function consolidated(Workspace $parent, string $period): array
    {
        /*
         | A KONSZOLIDÁLT OLVASÁS az EGYETLEN hely, ahol a global scope kikapcsol.
         |
         | A parent számlája a childjei kivonatait is tartalmazza — a global scope viszont
         | `workspace_id = parent`-re szűr, tehát a child sorokat elrejtené. A szűrő ezért
         | KÉZI és PONTOS: vagy a parent SAJÁT sora, vagy olyan sor, aminek a
         | `parent_workspace_id`-je ÉPP EZ a parent. Idegen tenant sora egyik ágon sem
         | férhet bele.
         |
         | A precedens ugyanebben a repóban a `TagStatistics` (nézet), a `ContactGdpr`
         | (mutátor-megkerülés) és a `UpdateIpReputationCommand` (szándékosan
         | tenant-független): mindegyik szűk, dokumentált, teszttel fedett kivétel.
         */
        $invoices = Invoice::query()
            ->withoutGlobalScopes()
            ->where('period', $period)
            ->where(fn ($query) => $query
                ->where('workspace_id', $parent->getKey())
                ->orWhere('parent_workspace_id', $parent->getKey()))
            ->with('workspace')
            ->get();

        $total = [];
        $children = [];

        foreach ($invoices as $invoice) {
            foreach ((array) $invoice->usage as $dimension => $value) {
                $total[$dimension] = ($total[$dimension] ?? 0) + (int) $value;
            }

            if ((int) $invoice->workspace_id !== (int) $parent->getKey()) {
                $children[] = [
                    'workspace' => (string) $invoice->workspace?->name,
                    'workspace_uuid' => (string) $invoice->workspace?->uuid,
                    'usage' => (array) $invoice->usage,
                ];
            }
        }

        return ['period' => $period, 'total' => $total, 'children' => $children];
    }
}
