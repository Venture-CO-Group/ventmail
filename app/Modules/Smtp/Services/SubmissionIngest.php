<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Services;

use App\Modules\Domains\Models\Domain;
use App\Modules\Messages\Services\MimeParser;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Data\SubmissionPrincipal;
use App\Modules\Smtp\Protocol\SmtpReply;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use InvalidArgumentException;
use Throwable;

/**
 * A `Smtp` modul EGYETLEN kijárata a küldés felé (ADR 0001, D2/D3).
 *
 * Itt dől el, hogy a beadott levél a `SendMessage` actionön — tehát a PreSendGate hat
 * kapuján — megy tovább. A modul SEM a `SendingDriver`-t, SEM a `MessageDispatcher`-t nem
 * ismeri (arch-teszt őrzi): így a gate nem kerülhető meg „gyorsításból", és a
 * `FakeDriver → PostalDriver` csere config marad.
 *
 * A workspace-kontextus a PRINCIPALBÓL jön, és a hívás UTÁN elengedjük — egy hosszan élő
 * listener-processzben a bent felejtett kontextus a következő kapcsolat levelét idegen
 * tenantnak könyvelné el.
 */
final class SubmissionIngest implements SubmissionSink
{
    public function __construct(
        private readonly SendMessage $send,
        private readonly MimeParser $parser,
        private readonly ControlHeaderMapper $controls,
        private readonly WorkspaceContext $context,
    ) {}

    public function accept(SubmissionPrincipal $principal, SmtpEnvelope $envelope, string $rawMime): SmtpReply
    {
        $workspace = Workspace::query()->whereKey($principal->workspaceId)->first();

        if ($workspace === null) {
            // Fail-closed: hitelesített hitelesítő, de nincs mögötte tenant (törölt
            // workspace). Átmeneti hibát adunk, hogy a kliens ne dobja el a levelet.
            return new SmtpReply(451, 'Temporary failure', '4.3.0');
        }

        $this->context->set($workspace);

        try {
            return $this->submit($principal, $envelope, $rawMime);
        } catch (InvalidArgumentException $e) {
            [$code] = array_pad(explode('|', $e->getMessage(), 2), 2, '');

            return new SmtpReply(550, 'Malformed message: '.$code, '5.6.0');
        } finally {
            $this->context->forget();
        }
    }

    private function submit(SubmissionPrincipal $principal, SmtpEnvelope $envelope, string $rawMime): SmtpReply
    {
        $parsed = $this->parser->parseHeaders($rawMime);

        // A hitelesítő DOMAINHEZ köthető (sub-account, márka-szeparáció). Az ADR D3
        // kimondja: eltérés → 550. Enélkül a mező fel volt töltve, de SOHA nem olvastuk —
        // vagyis az X domainre kiállított hitelesítővel Y-ról is lehetett küldeni.
        if ($principal->domainId !== null && ! $this->sendsFromOwnDomain($principal, $parsed['from_email'])) {
            return new SmtpReply(550, 'Sender domain not allowed for this credential', '5.7.1');
        }

        $controls = $this->controls->extract($parsed['headers']);

        if ($controls->error !== null) {
            return new SmtpReply(550, $controls->error, '5.6.0');
        }

        // A BORÍTÉK-címzett a mérvadó: a `RCPT TO` az, amit a kliens valóban kézbesíttetni
        // akar (a `To:` fejléc lehet csoportnév vagy hamis). Ha nincs, a fejlécre esünk
        // vissza — a gate mindkettőt átvizsgálja.
        $recipients = $envelope->recipients !== [] ? $envelope->recipients : $parsed['recipients'];

        try {
            $result = ($this->send)([
                'channel' => $principal->channel,
                'from_email' => $parsed['from_email'],
                'from_name' => $parsed['from_name'],
                'subject' => $parsed['subject'],
                'to' => $recipients,
                'headers' => $controls->headers,
                'tags' => $controls->tags,
                'variables' => $controls->variables,
                'options' => ['raw_mime' => true, 'mime_size' => strlen($rawMime), 'source' => 'smtp'],
                'deliverytime' => $controls->deliverytime,
                'testmode' => $principal->testmode || $controls->testmode,
                'raw_mime' => $rawMime,
                'raw_sha256' => hash('sha256', $rawMime),
                // Az SMTP-nek nincs `Idempotency-Key` fejléce, a probléma viszont valós: ha
                // a `250` elveszik, a kliens UGYANAZT a levelet küldi újra. A kulcsot ezért
                // determinisztikusan származtatjuk — a `Message-ID` előnyben, mert azt a
                // kliens az újrapróbáláskor is ugyanannak adja.
                'idempotency_key' => $this->idempotencyKey($principal, $envelope, $rawMime, $parsed),
            ]);
        } catch (Throwable $e) {
            report($e);

            // Fail-closed, DE átmeneti kóddal: a kliens újrapróbálja, a levél nem vész el.
            return new SmtpReply(451, 'Temporary failure', '4.3.0');
        }

        if (! $result->decision->allowed) {
            return GateReasonToSmtpReply::map((string) $result->decision->reason, (string) $result->message->uuid);
        }

        return new SmtpReply(250, 'Ok: queued as '.$result->message->uuid, '2.0.0');
    }

    private function sendsFromOwnDomain(SubmissionPrincipal $principal, string $fromEmail): bool
    {
        $name = substr(strrchr($fromEmail, '@') ?: '@', 1);

        return $name !== '' && Domain::query()
            ->whereKey($principal->domainId)
            ->where('name', strtolower($name))
            ->exists();
    }

    /** @param array{headers: array<string, string>} $parsed */
    private function idempotencyKey(
        SubmissionPrincipal $principal,
        SmtpEnvelope $envelope,
        string $rawMime,
        array $parsed,
    ): string {
        $messageId = $parsed['headers']['Message-Id'] ?? $parsed['headers']['Message-ID'] ?? null;

        $identity = $messageId ?? hash('sha256', $envelope->sender.'|'.implode(',', $envelope->recipients).'|'.$rawMime);

        return 'smtp:'.$principal->credentialUuid.':'.substr(hash('sha256', (string) $identity), 0, 40);
    }
}
