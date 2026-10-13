<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Contracts;

/**
 * A kész riport-PDF tárolója (D235). Ma a privát helyi diszk a megosztott volume-on
 * (`LocalPrivateReportStore`); // TODO ventVault: a ventVault élesítésekor ennek implementációja
 * cserélődik (HUMAN-QUEUE), a hívók nem változnak.
 */
interface ReportArtifactStore
{
    /** @return string a tárolt fájl relatív útvonala */
    public function put(string $workspaceUuid, string $reportUuid, string $bytes): string;

    public function exists(string $path): bool;

    /** @return resource|null */
    public function readStream(string $path);

    public function size(string $path): int;

    public function delete(string $path): void;
}
