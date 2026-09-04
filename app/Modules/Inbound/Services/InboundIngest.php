<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Contracts\InboundSink;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Data\InboundRecipient;
use App\Modules\Inbound\Jobs\ParseInboundMessage;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Protocol\SmtpReply;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A fogadó KIJÁRATA: a nyers bájtok letétele, és semmi több.
 *
 * A PARSE NEM ITT történik (ADR 0002). A bejövő MIME ismeretlen eredetű, és a
 * parser-sérülékenységek osztálya (mélységi rekurzió, zip-bomba, kvadratikus visszalépés)
 * pont az a fajta hiba, ami egy hosszan élő SMTP-fogadóban az ÖSSZES párhuzamos kapcsolatot
 * elviszi. A fogadó dolga a bájtok átvétele; ha a parse elhasal, az EGY üzenet hibája.
 *
 * A 250-es válasz ezért azt jelenti: „átvettem és eltettem" — nem azt, hogy „értelmeztem".
 * Ez megfelel az SMTP szemantikájának: az átvétellel a felelősség hozzánk került.
 */
final class InboundIngest implements InboundSink
{
    public function __construct(
        private readonly RawMailStore $store,
        private readonly WorkspaceContext $context,
    ) {}

    /** @param non-empty-list<InboundRecipient> $recipients */
    public function accept(array $recipients, SmtpEnvelope $envelope, string $rawMime): SmtpReply
    {
        try {
            foreach ($recipients as $recipient) {
                $this->store($recipient, $envelope, $rawMime);
            }
        } catch (Throwable $e) {
            // A KÜLDŐ ÚJRAPRÓBÁLHAT: a 4xx azt jelenti, „nálam most nem megy, gyere vissza".
            // Az 5xx-szel a levél végleg elveszne egy MI hibánk miatt.
            Log::error('A bejövő levél letétele nem sikerült.', ['error' => $e::class, 'message' => $e->getMessage()]);

            return new SmtpReply(451, 'Temporary failure storing message', '4.3.0');
        }

        return new SmtpReply(250, 'OK: message accepted', '2.0.0');
    }

    /**
     * CÍMZETTENKÉNT külön sor.
     *
     * Egy levél két saját címzettünkhöz KÉT különálló bejövő üzenet: külön route-olódnak,
     * külön továbbítódnak, és — ami fontosabb — külön tenanthoz tartozhatnak. Egyetlen közös
     * sor a két tenant adatát keverné.
     */
    private function store(InboundRecipient $recipient, SmtpEnvelope $envelope, string $rawMime): void
    {
        $workspace = Workspace::query()->whereKey($recipient->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $this->context->hasWorkspace() ? $this->context->workspace() : null;

        $this->context->set($workspace);

        try {
            // Az `uuid`-t a `HasUuids` tölti — kézzel megadva tömeges-hozzárendelési hibát
            // adna. A TÁROLÓKULCS viszont az uuid-ból épül, tehát előbb a modell.
            $message = InboundMessage::query()->create([
                'domain_id' => $recipient->domainId,
                // A NULL-SENDER (bounce) marad null: az `<>` nem cím, hanem annak a hiánya.
                'sender' => $envelope->sender === '' ? null : $envelope->sender,
                'recipient' => $recipient->address,
                'size' => strlen($rawMime),
                'sha256' => hash('sha256', $rawMime),
                'status' => 'received',
                'received_at' => now(),
            ]);

            $key = sprintf('inbound/%d/%s.eml', $recipient->workspaceId, (string) $message->uuid);

            $this->store->put($key, $rawMime);

            $message->forceFill(['storage_key' => $key])->save();

            ParseInboundMessage::dispatch($recipient->workspaceId, (string) $message->uuid);
        } finally {
            $previous === null ? $this->context->forget() : $this->context->set($previous);
        }
    }
}
