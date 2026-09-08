<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Console;

use App\Modules\Deliverability\Services\DmarcReportIngestor;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * DMARC RUA jelentések BETÖLTÉSE fájlból vagy könyvtárból (44. kritérium).
 *
 * A jelentések ma kézzel (vagy egy egyszerű postafiók-letöltéssel) kerülnek a lemezre; az
 * A4 inbound route-jára kötött automatikus betöltés a devops-sáv feladata (C2). A parse és a
 * perzisztálás viszont MA működik, és ugyanazon az úton fog: ez a parancs a szolgáltatás
 * belépési pontja, nem egy külön implementáció.
 *
 * A HIBÁS jelentés nem állítja meg a futást: egy idegen domainről szóló vagy sérült fájl
 * miatt a többi sem töltődne be — és a jelentések kötegben érkeznek.
 */
final class IngestDmarcReportsCommand extends Command
{
    protected $signature = 'deliverability:ingest-dmarc {path : fájl vagy könyvtár}';

    protected $description = 'DMARC RUA jelentések betöltése (gzip-elt vagy nyers XML).';

    public function handle(DmarcReportIngestor $ingestor): int
    {
        $path = (string) $this->argument('path');

        $files = is_dir($path)
            ? array_values(array_filter((array) glob(rtrim($path, '/').'/*'), 'is_file'))
            : [$path];

        if ($files === [] || ! is_readable((string) $files[0])) {
            $this->error("Nem olvasható: {$path}");

            return self::FAILURE;
        }

        $loaded = 0;
        $skipped = 0;

        foreach ($files as $file) {
            try {
                $report = $ingestor->ingest((string) file_get_contents((string) $file));

                $loaded++;

                $this->line(sprintf(
                    '%s — %s, %d üzenet, %.2f%% igazodás',
                    basename((string) $file),
                    $report->org_name,
                    $report->total,
                    $report->passRate(),
                ));
            } catch (InvalidArgumentException|RuntimeException $e) {
                $skipped++;

                $this->warn(basename((string) $file).' — kihagyva: '.$e->getMessage());
            }
        }

        $this->info("Betöltve: {$loaded}, kihagyva: {$skipped}");

        return self::SUCCESS;
    }
}
