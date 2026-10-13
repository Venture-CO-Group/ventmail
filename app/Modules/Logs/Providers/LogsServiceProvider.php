<?php

declare(strict_types=1);

namespace App\Modules\Logs\Providers;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Console\PurgeLogsCommand;
use App\Modules\Logs\Console\RedactSubjectCommand;
use App\Modules\Logs\Services\DomainStatsRecorder;
use App\Modules\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Logs modul — kereshető napló, üzenet-trace, SMTP-párbeszéd, domain-statisztika.
 */
final class LogsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // SCOPED: a domain-memoizálás egy kérés / egy job idejére szól (a worker több tenant
        // jobját futtatja egymás után).
        $this->app->scoped(DomainStatsRecorder::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeLogsCommand::class, RedactSubjectCommand::class]);
        }

        /*
         | v1.1 · 7. modul (D175): a domainenkénti napi számlálók az esemény-folyamból.
         |
         | A HIBA NEM BUKTATJA EL az esemény rögzítését: a napló és a webhook forrása az
         | esemény, egy statisztika-sor nem érhet annyit, hogy egy kézbesítési esemény elvesszen.
         */
        MessageEvent::created(function (MessageEvent $event): void {
            try {
                // SAVEPOINT (RS4): a recorder lekérdezésének hibája nem mérgezi a hívó
                // tranzakcióját (pl. a Postal-webhook feldolgozása tranzakcióban fut).
                DB::transaction(fn () => app(DomainStatsRecorder::class)->record($event));
            } catch (Throwable $e) {
                Log::warning('A domain-statisztika nem frissült', [
                    'event_type' => (string) $event->type,
                    'exception' => $e::class,
                ]);
            }
        });
    }
}
