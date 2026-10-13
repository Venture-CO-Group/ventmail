<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Services\TestRecipients;

/**
 * 3. kapu — marketing CSAK megerősített (double opt-in) consenttel (golden rule 4).
 *
 * A `transactional` csatornán nincs consent-követelmény: a szolgáltatás működéséhez tartozó
 * levél nem hírlevél.
 */
final class ConsentGate implements Gate
{
    public function __construct(private readonly ConsentChecker $consent) {}

    public function name(): string
    {
        return 'consent';
    }

    public function check(Message $message): GateResult
    {
        // MEGFORDÍTOTT logika: nem azt kérdezzük, „marketing-e", hanem hogy
        // consent-mentes-e. Egy ismeretlen csatorna így NEM csúszik át.
        $channel = Channel::from((string) $message->channel);

        if (! $channel->requiresConsent()) {
            return GateResult::pass('tranzakciós csatorna');
        }

        /*
         | D52 · A SABLON-TESZT KIVÉTELE — szűk, explicit, auditált.
         |
         | A marketing sablon tesztje a `campaign` csatornán megy (mk pool, List-Unsubscribe),
         | a címzett viszont a feladó SAJÁT csapata (workspace-tag) vagy a platform seed-címe:
         | ő nem feliratkozó, consent-rekordja nincs. Két feltétel EGYÜTT kell:
         |  1. az üzenet `template_test` eredetű (a `/v1` és az SMTP ezt nem tudja beállítani —
         |     a `MailgunPayload` opció-allowlistje zárt);
         |  2. MINDEN címzett a `TestRecipients` listáján van (itt, a kapuban újra ellenőrizve,
         |     nem a hívóra bízva).
         | Minden más kapu (küldési mód, marketing-kapcsoló, suppression, kvóta) változatlanul fut.
         */
        if (($message->options['studio_origin'] ?? null) === 'template_test'
            && app(TestRecipients::class)->allows(array_map(static fn ($recipient) => (string) $recipient, $message->recipients))) {
            app(AuditLogger::class)->log('sending.consent.template_test_exception', null, context: [
                'message_uuid' => (string) $message->uuid,
                'recipients' => count($message->recipients),
                'channel' => $channel->value,
            ]);

            return GateResult::pass(sprintf('sablon-teszt kivétel: %d belső címzett (tag / seed)', count($message->recipients)));
        }

        // Egyetlen lekérdezés: 1000 címzettnél a címzettenkénti ellenőrzés 1000 körutazás
        // lenne, szinkron a kérésben.
        $missing = $this->consent->withoutConsent(
            array_map(static fn ($recipient) => (string) $recipient, $message->recipients),
            $channel->value
        );

        if ($missing !== []) {
            // Nyers cím SOHA nem kerül a törölhetetlen auditba — darabszám elég.
            return GateResult::reject(
                GateReason::NoConsent,
                sprintf('%d címzettnek nincs megerősített consentje (%s)', count($missing), $channel->value)
            );
        }

        return GateResult::pass();
    }
}
