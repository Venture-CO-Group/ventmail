<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Kimenő e-mail őr — golden rule 2: a PreSendGate megkerülhetetlen.
 *
 * A Laravel a keretrendszer alapértelmezéseit REKURZÍVAN visszamergeli a saját
 * config-fájljainkra, ezért a `smtp`/`ses`/`postmark`/`resend`/`sendmail` transportok
 * és a hozzájuk tartozó `services` kulcsok pusztán a config szerkesztésével nem
 * tüntethetők el. Márpedig egyetlen `Mail::to(...)->send()` ezeken kimenne a gate,
 * a domain-hitelesítés és az audit MELLETT.
 *
 * Ezért itt futásidőben metsszük a listát, és hangosan elszállunk, ha valaki tiltott
 * transportot állít be. A ventMail minden kimenő levele a Sending modul
 * SendingDriverén megy (M0-3).
 */
final class OutboundMailGuardServiceProvider extends ServiceProvider
{
    /** Ami maradhat: fejlesztői napló és teszt-gyűjtő. */
    private const ALLOWED_MAILERS = ['log', 'array', 'failover'];

    /** Harmadik felű e-mail szolgáltató a ventMailben nem értelmezett. */
    private const FORBIDDEN_SERVICES = ['postmark', 'resend', 'ses', 'mailgun', 'sendgrid'];

    public function register(): void
    {
        $mailers = Arr::only(config('mail.mailers', []), self::ALLOWED_MAILERS);
        $mailers['failover'] = ['transport' => 'failover', 'mailers' => ['log'], 'retry_after' => 60];

        $default = (string) config('mail.default');

        if (! array_key_exists($default, $mailers)) {
            throw new RuntimeException(
                "Tiltott MAIL_MAILER: '{$default}'. A ventMail kimenő útja a Sending modul "
                .'SendingDrivere (PreSendGate) — a Laravel mailer csak napló lehet: '
                .implode(', ', self::ALLOWED_MAILERS).'.'
            );
        }

        config([
            'mail.mailers' => $mailers,
            'services' => Arr::except(config('services', []), self::FORBIDDEN_SERVICES),
        ]);
    }
}
