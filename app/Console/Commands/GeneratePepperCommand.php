<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Az `AUDIT_PEPPER` generálása.
 *
 * A pepper KÜLÖN titok az `APP_KEY`-től: azzal hashelünk API-kulcsot, e-mail- és
 * IP-pszeudonimizálást. Ha az `APP_KEY`-t használnánk, annak rotációja egy csapásra
 * érvénytelenítené az összes ügyfél összes API-kulcsát.
 */
final class GeneratePepperCommand extends Command
{
    protected $signature = 'ventmail:pepper';

    protected $description = 'Új AUDIT_PEPPER értéket generál (a .env-be kell másolni).';

    public function handle(): int
    {
        $this->line(bin2hex(random_bytes(32)));

        return self::SUCCESS;
    }
}
