<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * ÉRINTETTI TÖRLÉSI KÉRÉS (GDPR Art. 17) — egy címhez tartozó személyes adat eltávolítása.
 *
 * A retenció IDŐ alapján takarít; ez SZEMÉLY alapján. A kettő nem helyettesíti egymást: egy
 * törlési kérésre nem válasz az, hogy „kilencven nap múlva magától eltűnik".
 *
 * A TÉNY megmarad, a SZEMÉLY tűnik el: az esemény típusa, ideje és a workspace ott marad,
 * tehát a kézbesítési statisztika és a panasz-bizonyíték nem sérül. Ez nem kibúvó: az
 * anonimizált aggregátum már nem személyes adat, és a törlési jog nem terjed ki rá.
 *
 * A `message_events` az egyetlen tábla, ahol adatbázis-trigger őrzi, hogy CSAK redakció
 * mehet át (a napló nem írható át) — a többi három PII-tárolót ez a parancs kezeli.
 */
final class RedactSubjectCommand extends Command
{
    protected $signature = 'logs:redact-subject {email : Az érintett e-mail-címe}';

    protected $description = 'Egy címhez tartozó személyes adat eltávolítása a naplóból (GDPR Art. 17).';

    public function handle(WorkspaceContext $context, DomainNormalizer $normalizer): int
    {
        // A TÁROLT alakra kell keresni: a helyi rész változatlan, a domain punycode. Naiv
        // `strtolower()`-rel az ékezetes domainű címzett kérése csendben nulla sort érintene
        // — és azt jelentenénk vissza, hogy „nincs mit törölni".
        $email = $normalizer->email(trim((string) $this->argument('email')));

        if ($email === '' || ! str_contains($email, '@')) {
            $this->error('Érvénytelen e-mail-cím.');

            return self::FAILURE;
        }

        $redacted = ['events' => 0, 'smtp' => 0, 'attempts' => 0, 'webhooks' => 0];

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $email, &$redacted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    MessageEvent::query()
                        ->where('recipient', $email)
                        ->whereNull('redacted_at')
                        ->each(function (MessageEvent $event) use (&$redacted): void {
                            $event->redactPersonalData();
                            $redacted['events']++;
                        });

                    $redacted['smtp'] += SmtpTransaction::query()
                        ->where('recipient', $email)
                        ->update(['recipient' => null, 'response_text' => null, 'lines' => [], 'redacted_at' => now()]);

                    $redacted['attempts'] += DeliveryAttempt::query()
                        ->where('recipient', $email)
                        ->update(['recipient' => null, 'response' => null, 'redacted_at' => now()]);

                    // A webhook-payload a címzettet ÉS a `v:*` változókat is viszi: a sort
                    // egészében ürítjük, a kézbesítés ténye (státusz, kísérlet) megmarad.
                    $redacted['webhooks'] += WebhookDelivery::query()
                        ->whereJsonContains('payload->message->recipient', $email)
                        ->update(['payload' => [], 'response_excerpt' => null, 'redacted_at' => now()]);
                }
            });
        } finally {
            $context->forget();
        }

        $this->info(sprintf(
            'Redaktálva — esemény: %d, SMTP-párbeszéd: %d, kísérlet: %d, webhook-sor: %d',
            $redacted['events'],
            $redacted['smtp'],
            $redacted['attempts'],
            $redacted['webhooks'],
        ));

        return self::SUCCESS;
    }
}
