<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\MonthlyClose;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * HAVI ZÁRÁS (78. kritérium) — a periódus lezárása és a kivonatok elkészítése.
 *
 * ## Miért a PARENTEKET járja végig, nem minden workspace-t
 *
 * Mert a zárás KONSZOLIDÁLT: a `closeConsolidated()` a parentet ÉS a childjeit egyszerre
 * zárja, a child kivonatát `parent_workspace_id`-vel megjelölve. Ha minden workspace-t
 * külön zárnánk, egy child kivonata parent-jelölés NÉLKÜL készülne el (a `close()` nem
 * tudná, hogy sub-account), és a konszolidált számlából kimaradna — a parent kevesebbet
 * fizetne, a child pedig egy számlát kapna, amit nem ő fizet.
 *
 * ## Az ELŐZŐ periódust zárja, nem a folyót
 *
 * Egy futó hónap lezárása azt jelentené, hogy a hónap hátralévő fogyása elveszik (a
 * `Metering` a lezárt periódust kihagyja). A parancs alapból az ELŐZŐ hónapot zárja; a
 * `--period` a kézi/pótló futásra van.
 */
final class CloseBillingPeriodCommand extends Command
{
    protected $signature = 'billing:close {--period= : YYYY-MM, alapból az ELŐZŐ hónap}';

    protected $description = 'A számlázási periódus lezárása és a kivonatok elkészítése (konszolidáltan).';

    public function handle(WorkspaceContext $context, MonthlyClose $close): int
    {
        $period = (string) ($this->option('period') ?: now()->utc()->subMonth()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $this->error('A periódus alakja `YYYY-MM`.');

            return self::FAILURE;
        }

        /*
         | CSAK MÚLTBELI periódus zárható — és ez nem formalitás.
         |
         | A review megmérte, hogy a FOLYÓ periódus lezárása CSENDES ingyen küldést ad: a
         | zárás után öt kiküldött levél, `sends_counter` VÁLTOZATLAN, nulla kivétel, nulla
         | naplósor. A `Metering::write()` „csendben kihagyom" ága a késve érkező webhookra
         | helyes döntés — de nem szabad ugyanígy elnyelnie egy ÉLŐ küldést.
         |
         | A jövőbeli periódus ugyanígy átment, és egy 2030-as kivonat semmit nem jelent.
         */
        if ($period >= now()->utc()->format('Y-m')) {
            $this->error(sprintf(
                'A(z) %s periódus még nem zárható: csak MÚLTBELI periódus zárható le. A folyó '
                .'periódus lezárása a hátralévő küldéseket CSENDBEN elnyelné (a mérés a lezárt '
                .'periódust kihagyja), a jövőbeli pedig értelmezhetetlen kivonatot adna.',
                $period
            ));

            return self::FAILURE;
        }

        $closed = 0;
        $failed = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            /*
             | CSAK A PARENTEK és az ÖNÁLLÓ workspace-ek: a childokat a
             | `closeConsolidated()` zárja. A `whereDoesntHave('parents')` azt jelenti,
             | hogy „nincs szülője", tehát vagy önálló, vagy ő maga a parent.
             */
            Workspace::query()
                ->withTrashed()
                ->whereDoesntHave('parents')
                ->orderBy('id')
                ->chunkById(50, function ($workspaces) use ($close, $period, &$closed, &$failed): void {
                    foreach ($workspaces as $workspace) {
                        try {
                            $closed += count($close->closeConsolidated($workspace, $period));
                        } catch (Throwable $e) {
                            // EGY TENANT HIBÁJA nem állítja meg a zárást: a többi számlája
                            // nem várhat egy hibás sub-account miatt.
                            // A KIVÉTEL RÉSZLETE is kell: a puszta osztálynév után nincs
                            // miből kideríteni, MIÉRT maradt el egy tenant számlája.
                            $this->warn(sprintf(
                                'A(z) %s zárása elhasalt: %s — %s',
                                (string) $workspace->uuid,
                                $e::class,
                                $e->getMessage()
                            ));

                            report($e);

                            $failed++;
                        }
                    }
                });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Periódus: {$period}, lezárt kivonat: {$closed}, hibás: {$failed}");

        /*
         | A HIBÁS ZÁRÁS NEM SIKER.
         |
         | A parancs korábban feltétel nélkül `SUCCESS`-t adott: egy kimaradt havi kivonat
         | `hibás: 1`-ként lefutott a naplóban, az ütemező pedig sikernek látta — vagyis
         | senki nem tudta meg, hogy egy tenant számlája elmaradt. Pénzügyi útnál a
         | kilépési kód a riasztás alapja.
         */
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
