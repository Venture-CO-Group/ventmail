<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Console;

use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Contracts\InboundSink;
use App\Modules\Inbound\Protocol\MxSession;
use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Services\AuthThrottle;
use App\Modules\Smtp\Transport\StreamSubmissionServer;
use Illuminate\Console\Command;

/**
 * `php artisan inbound:serve --port=25`
 *
 * A BEJÖVŐ (MX) listener. UGYANAZ a transzport, mint a submissioné — socket, TLS,
 * időkorlát, per-IP keret, tarpit —, de MÁS protokoll-állapotgép (`MxSession`), mert a két
 * oldal invariánsa különbözik (ADR 0002):
 *
 *  - submission (587/465): „nincs `MAIL FROM` hitelesítés nélkül";
 *  - MX (25): „nincs elfogadás olyan címzettre, aki nem a MI verifikált inbound-domainünk",
 *    és `AUTH` ezen a porton nem is létezik.
 *
 * A PORT-POLITIKA is külön: ez a listener KIZÁRÓLAG a bejövő portokon indulhat, a
 * submission-listener pedig kizárólag a sajátjain. Egy elgépelt `--port` nem cserélheti fel
 * a két bizalmi modellt.
 */
final class ServeMxCommand extends Command
{
    protected $signature = 'inbound:serve
        {--port=25 : a kiszolgált port}
        {--host=0.0.0.0 : a kötendő cím}';

    protected $description = 'Bejövő (MX) SMTP listener a 25-ös porton.';

    public function handle(
        InboundRecipientResolver $resolver,
        InboundSink $sink,
        SmtpAuthenticator $authenticator,
        AuthThrottle $throttle,
    ): int {
        if (! config('inbound.enabled')) {
            $this->warn('A bejövő fogadás ki van kapcsolva (INBOUND_ENABLED=false).');

            return self::SUCCESS;
        }

        $port = (int) $this->option('port');

        // A KÉT PORTLISTA DISZJUNKTSÁGA futásidőben kikényszerítve, nem tesztben remélve.
        // Az `INBOUND_ALLOWED_PORTS` env-ből jön: egy `INBOUND_ALLOWED_PORTS=587` mellett a
        // port-őr átengedné a HITELESÍTÉS NÉLKÜLI MX-állapotgépet a submission portjára.
        $overlap = array_intersect(
            (array) config('inbound.allowed_ports'),
            (array) config('smtp.allowed_ports'),
        );

        if ($overlap !== []) {
            $this->error(sprintf(
                'A bejövő és a submission portlista átfedi egymást (%s). A két bizalmi modell nem osztozhat porton.',
                implode(', ', $overlap),
            ));

            return self::FAILURE;
        }

        $server = new StreamSubmissionServer(
            $authenticator,
            // A submission-sink SOHA nem hívódik meg ezen a listeneren: az `MxSession` nem
            // is ismeri. A paraméter azért van itt, mert a transzport konstruktora közös —
            // az MX-oldal kijárata az `InboundSink`, amit a session kap meg.
            app(SubmissionSink::class),
            $throttle,
            sessionFactory: fn (ChannelSecurity $security) => new MxSession(
                $resolver,
                $sink,
                $security,
                (string) config('inbound.hostname'),
            ),
            allowedPorts: (array) config('inbound.allowed_ports'),
            // SAJÁT limit-készlet: a bejövő oldal elé nem a mi terheléselosztónk áll, hanem
            // a világ összes MTA-ja. A submission PROXY-protokoll beállítása itt minden
            // valódi küldőt elutasítana.
            configPrefix: 'inbound',
        );

        $this->info("ventMail MX — {$this->option('host')}:{$port}");

        // A 25-ös port MINDIG plaintextként indul: a bejövő levelezés STARTTLS-szel emel,
        // implicit TLS itt nincs (a küldő MTA-k a 25-ösön nem is várnák).
        $server->serve((string) $this->option('host'), $port, false);

        return self::SUCCESS;
    }
}
