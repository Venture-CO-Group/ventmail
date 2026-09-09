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
         | A LEZÁRT PERIÓDUS KIHAGYÁSA — csendben, és jó okkal.
         |
         | Egy késve beérkező webhook-esemény (bounce, panasz) napokkal a zárás után is
         | megérkezhet. Az esemény LEGITIM, csak a periódus már el van számolva: kivételt
         | dobni azt jelentené, hogy egy normális üzemi helyzet hibaként jelenik meg a
         | naplóban, és az igazi hibák elveszne a zajban.
         */
        if ($this->isClosed($workspaceId, $period, $dimension)) {
            return;
        }

        // A HÁROM VISELKEDÉS ütközési ága — SQL-ben, mert a versenyt az adatbázis dönti el.
        $onConflict = match ($dimension->behaviour()) {
            UsageBehaviour::Counter => ['value' => DB::raw('usage_records.value + excluded.value')],
            UsageBehaviour::Peak => ['value' => DB::raw('greatest(usage_records.value, excluded.value)')],
            UsageBehaviour::Snapshot => ['value' => DB::raw('excluded.value')],
        };

        UsageRecord::query()->upsert(
            [[
                'workspace_id' => $workspaceId,
                'period' => $period,
                'dimension' => $dimension->value,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['workspace_id', 'period', 'dimension'],
            array_merge($onConflict, ['updated_at' => now()])
        );
    }

    private function isClosed(int $workspaceId, string $period, UsageDimension $dimension): bool
    {
        return UsageRecord::query()
            ->where('period', $period)
            ->where('dimension', $dimension->value)
            ->whereNotNull('closed_at')
            ->exists();
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
