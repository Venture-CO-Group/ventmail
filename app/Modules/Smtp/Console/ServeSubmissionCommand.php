<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Console;

use App\Modules\Smtp\Transport\StreamSubmissionServer;
use Illuminate\Console\Command;

/**
 * `php artisan smtp:serve --port=587|465`
 *
 * Egy processz EGY portot szolgál ki (két compose-service). A port-ellenőrzés INDULÁSKOR
 * történik és hangos: a 25-ös port a bejövő (MX) oldalé, más parserrel és más bizalmi
 * modellel (A4) — ide véletlenül sem kerülhet.
 */
final class ServeSubmissionCommand extends Command
{
    protected $signature = 'smtp:serve
        {--port=587 : a kiszolgált port (587 STARTTLS, 465 implicit TLS)}
        {--host=0.0.0.0 : a kötendő cím}';

    protected $description = 'SMTP submission listener (587 STARTTLS / 465 implicit TLS).';

    public function handle(StreamSubmissionServer $server): int
    {
        if (! config('smtp.enabled')) {
            $this->warn('Az SMTP submission ki van kapcsolva (SMTP_SUBMISSION_ENABLED=false).');

            return self::SUCCESS;
        }

        $port = (int) $this->option('port');

        $this->info("ventMail SMTP submission — {$this->option('host')}:{$port}");

        // A 465 implicit TLS-sel indul, az 587 STARTTLS-szel emel.
        $server->serve((string) $this->option('host'), $port, in_array($port, [465, 2465], true));

        return self::SUCCESS;
    }
}
