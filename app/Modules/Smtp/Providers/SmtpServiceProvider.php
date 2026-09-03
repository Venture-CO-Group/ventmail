<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Providers;

use App\Modules\Smtp\Console\ServeSubmissionCommand;
use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Services\CredentialAuthenticator;
use App\Modules\Smtp\Services\SubmissionIngest;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Smtp modul — submission (587/465).
 *
 * A modul EGYETLEN kijárata a `SubmissionIngest`, ami a `SendMessage` actiont hívja. A
 * `SendingDriver`-t és a `MessageDispatcher`-t SZÁNDÉKOSAN nem ismeri (arch-teszt őrzi):
 * így a PreSendGate az SMTP-úton sem kerülhető meg (golden rule 2).
 */
final class SmtpServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmtpAuthenticator::class, CredentialAuthenticator::class);
        $this->app->bind(SubmissionSink::class, SubmissionIngest::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ServeSubmissionCommand::class]);
        }
    }
}
