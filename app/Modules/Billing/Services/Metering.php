<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\UsageBehaviour;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A FOGYÁS RÖGZÍTÉSE (75., 80. kritérium) — egyetlen choke point.
 *
 * ## Miért EGY hely
 *
 * Mert a fogyás-szám a SZÁMLA alapja. Egy elveszett inkrementum bevétel-kiesés, egy
 * duplikált hamis számla — és mindkettő CSENDES: nincs kivétel, nincs piros teszt, csak egy
 * összeg, ami nem stimmel. Hat dimenzió és három viselkedés mellett a hívási helyeken
 * elrendezett logika garantáltan összekeveredne (valaki a `dedicated_ips`-t inkrementálná,
 * és a tenant öt IP-t fizetne három helyett).
 *
 * ## AZ IDEMPOTENCIA AZ ADATBÁZISBAN VAN
 *
 * `upsert` + egyedi index `(workspace_id, period, dimension)`, és az ütközési ág SQL-ben
 * számol (`value + n`, `greatest`, felülírás) — nem „beolvasom, hozzáadom, visszaírom",
 * ami két párhuzamos küldés között elveszít egy levelet. Ez ugyanaz az elv, amit a B3
 * journey-motor és a B4 klikk-térkép is használ: a versenyt az index dönti el, nem az
 * alkalmazás jóindulata.
 *
 * ## A LEZÁRT PERIÓDUST NEM ÍRJUK
 *
 * Egy késve beérkező webhook-esemény bármikor megpróbálhatja. A szolgáltatás CSENDBEN
 * kihagyja (nem hiba: az esemény legitim, csak a periódus már el van számolva), az
 * adatbázis-trigger pedig a végső határ — ha valaha egy nyers `UPDATE` mégis megpróbálná,
 * az kivétel.
 */
final class Metering
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /** SZÁMLÁLÓ-dimenzió növelése (küldés, validáció, inbound). */
    public function increment(UsageDimension $dimension, int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }

        $this->write($dimension, $by);
    }

    /**
     * CSÚCS-dimenzió megfigyelése: csak akkor ír, ha az érték NAGYOBB.
     *
     * A `greatest()` SQL-ben dönt, nem PHP-ben: két párhuzamos megfigyelés között
     * különben a kisebb írná felül a nagyobbat.
     */
    public function observePeak(UsageDimension $dimension, int $value): void
    {
        $this->write($dimension, $value);
    }

    /** PILLANATKÉP-dimenzió: felülír (nem összegez). */
    public function snapshot(UsageDimension $dimension, int $value): void
    {
        $this->write($dimension, $value);
    }

    /** A JELENLEGI érték az AKTUÁLIS periódusban. */
    public function current(UsageDimension $dimension, ?string $period = null): int
    {
        $this->assertContext();

        return (int) UsageRecord::query()
            ->where('period', $period ?? $this->period())
            ->where('dimension', $dimension->value)
            ->value('value');
    }

    /**
     * A TELJES fogyás egy periódusra — MIND A HAT dimenzióval.
     *
     * A nem használt dimenzió NULLÁVAL szerepel, nem hiányzó kulcsként: a hiányzó azt
     * jelenti, hogy „nem tudjuk", a nulla azt, hogy „nem volt fogyás". A ventPay oldalán
     * ez a különbség számla-tétel.
     *
     * @return array<string, int>
     */
    public function forPeriod(string $period): array
    {
        $this->assertContext();

        $rows = UsageRecord::query()
            ->where('period', $period)
            ->pluck('value', 'dimension');

        $usage = [];

        foreach (UsageDimension::values() as $dimension) {
            $usage[$dimension] = (int) ($rows[$dimension] ?? 0);
        }

        return $usage;
    }

    /**
     * AZ AKTUÁLIS PERIÓDUS kulcsa (`YYYY-MM`).
     *
     * UTC-ben, és ez tudatos: a periódus-határ nem függhet attól, melyik tenant melyik
     * időzónában van — különben ugyanaz a küldés két különböző hónapba eshetne két
     * felhasználó szemszögéből, és a hónap végi összeg nem lenne reprodukálható.
     */
    public function period(): string
    {
        return now()->utc()->format('Y-m');
    }

    private function write(UsageDimension $dimension, int $value): void
    {
        $workspaceId = $this->assertContext();
        $period = $this->period();

        /*
         | EGYETLEN UTASÍTÁS — és ez a lényeg, nem a takarékosság.
         |
         | Korábban két utasítás volt: egy `isClosed()` SELECT, majd az `upsert`. A review
         | ezt két kapcsolaton megmérte, és a következmény FOGYÁS-VESZTÉS volt:
         |
         |  1. amíg a zárás tranzakciója nyitva van, a `SELECT` `false`-t ad (nincs commit),
         |     tehát a mérés „szabadnak" látja a periódust;
         |  2. az `upsert` BEBLOKKOL a sorzáron a zárás teljes idejére (mérve
         |     `statement_timeout`-ba futott — vagyis a KÉRÉS is beakad);
         |  3. a zárás commitja után ugyanaz az `upsert` a triggeren hasal el;
         |  4. a `SendMessage` a kivételt `Log::warning`-ként elnyeli → A LEVÉL KIMEGY, A
         |     FOGYÁS ELVESZIK.
         |
         | A `WHERE usage_records.closed_at IS NULL` az `ON CONFLICT DO UPDATE`-ben ATOMIVÁ
         | teszi a kihagyást: ha a sor közben lezárult, az UPDATE egyszerűen nem talál sort
         | (nincs kivétel, nincs veszteség-jelentés — a fogyás a lezárt periódusból KIMARAD,
         | ami a szándék), és a SELECT is megszűnik. Ez felezi a lekérdezés-számot minden
         | küldési úton.
         |
         | A `DO NOTHING`-ot NEM használjuk a beszúrási ágon: ha a sor még nem létezik, az
         | INSERT-nek meg KELL történnie — egy lezárt periódusba beeső ÚJ dimenzió sorát a
         | zárás előlétrehozza (`MonthlyClose`), tehát ide nem jut el.
         */
        $behaviour = match ($dimension->behaviour()) {
            UsageBehaviour::Counter => 'usage_records.value + excluded.value',
            UsageBehaviour::Peak => 'greatest(usage_records.value, excluded.value)',
            UsageBehaviour::Snapshot => 'excluded.value',
        };

        DB::statement(
            <<<SQL
                insert into usage_records
                    (workspace_id, period, dimension, value, created_at, updated_at)
                values (?, ?, ?, ?, now(), now())
                on conflict (workspace_id, period, dimension) do update
                    set value = {$behaviour}, updated_at = now()
                    where usage_records.closed_at is null
            SQL,
            [$workspaceId, $period, $dimension->value, $value]
        );
    }

    /**
     * A MÉRT WORKSPACE a KONTEXTUS — nincs explicit paraméter, és nincs scope-kikapcsolás.
     *
     * ## Miért nem kell explicit workspace
     *
     * Mert MINDEN valódi hívási út már a mért tenant kontextusában fut: a `SendMessage` a
     * küldő tenantéban, az `InboundIngest` a címzett feloldása UTÁN állítja be, a
     * `ProcessValidationJob` a job workspace-ét, a havi zárás parancsa pedig
     * workspace-enként. Egy explicit `Workspace` paraméter és a global scope kikapcsolása
     * itt felesleges kiskaput nyitott volna — az arch-őr (`ScopeBypassGuardTest`) helyesen
     * tüzelt rá, és a javítás a szabály betartása volt, nem a kivétel-lista bővítése.
     *
     * A szűrést így a `UsageRecord` global scope-ja adja, nem egy kézzel odaírt `where`,
     * amit el lehet felejteni. A számlázásban a legrosszabb hiba a MÁSNAK könyvelt fogyás.
     *
     * ## A kontextus HIÁNYA kivétel, nem „minden tenant"
     *
     * Egy fogyás-sor workspace nélkül értelmezhetetlen. A fail-closed itt nem óvatosság:
     * a `WorkspaceScope` `1 = 0` ága egy `upsert`-re nem hat (az INSERT), tehát kontextus
     * nélkül a sor `workspace_id`-je hiányos lenne — és a hiba a hónap végén derülne ki.
     *
     * @return int a mért workspace azonosítója
     */
    private function assertContext(): int
    {
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException(
                'A fogyás workspace-kontextus nélkül nem rögzíthető: a hívó állítson kontextust '
                .'(minden valódi küldési, fogadási és zárási út ezt teszi).'
            );
        }

        return $this->context->workspaceId();
    }
}
