<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Services\WorkspaceContext;
use RuntimeException;

/**
 * A `SendingDriver::send()` EGYETLEN hívója (golden rule 2).
 *
 * Két garanciát ad:
 * 1. Csak `accepted` állapotú — tehát a PreSendGate hat kapuján átment — üzenetet küld.
 * 2. IDEMPOTENS: az `accepted → sending` átmenet feltételes UPDATE, amelyet az adatbázis
 *    egyszer enged. Kétszeri dispatch így egy küldés, versenyhelyzetben is.
 */
final class MessageDispatcher
{
    public function __construct(
        private readonly SendingDriver $driver,
        private readonly WorkspaceContext $context,
    ) {}

    /** Az az állapot, ahonnan a küldés indulhat: a PreSendGate hat kapuján átment üzenet. */
    private const DISPATCHABLE = 'accepted';

    /** Ezekbe az állapotokba a dispatch MÁR eljutott — az ismételt hívás no-op. */
    private const ALREADY_DISPATCHED = ['sending', 'delivered', 'failed'];

    public function dispatch(Message $message): void
    {
        // Két külön dolog: a GATE MEGKERÜLÉSE hiba, az ISMÉTELT küldés viszont csak no-op.
        if (in_array($message->status, self::ALREADY_DISPATCHED, true)) {
            return;
        }

        if ($message->status !== self::DISPATCHABLE) {
            throw new RuntimeException(sprintf(
                'A(z) %s üzenet állapota „%s" — csak a PreSendGate-en átment, `accepted` '
                .'üzenet küldhető (golden rule 2).',
                $message->uuid,
                $message->status
            ));
        }

        // A tenant-kontextus itt is kötelező: enélkül a scope 0 sort adna, és a küldés
        // NÉMÁN elmaradna. Jobban járunk hangos hibával (a queue-jobnak be kell állítania).
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException(
                'A küldéshez workspace-kontextus kell — állítsd be a jobban a WorkspaceContextet.'
            );
        }

        // Atomikus állapotátmenet ELOQUENTEN, tehát a tenant global scope-pal együtt:
        // aki megnyeri, az küld; a második hívó 0 sort érint. (Nyers DB::table itt
        // megkerülné az izolációt — golden rule 6.)
        $claimed = Message::query()
            ->whereKey($message->getKey())
            ->where('status', self::DISPATCHABLE)
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed === 0) {
            return; // már fut vagy lefutott — nincs dupla küldés
        }

        $result = $this->driver->send($message);

        $message->forceFill([
            'status' => $result->accepted ? 'delivered' : 'failed',
            'reason' => $result->error,
            'sent_at' => now(),
        ])->save();

        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => $result->accepted ? 'delivered' : 'failed',
            'recipient' => $message->recipients[0] ?? null,
            'channel' => $message->channel,
            'reason' => $result->error,
            'tags' => $message->tags,
            'variables' => $message->variables,
            'payload' => ['provider_message_id' => $result->providerMessageId],
        ]);
    }
}
