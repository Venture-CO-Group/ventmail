<?php

declare(strict_types=1);

namespace App\Modules\Sending\Actions;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Sending\Gate\GateDecision;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use Illuminate\Support\Arr;

/**
 * A küldés belépési pontja — a PreSendGate-en át.
 *
 * Ez az M0 globális DoD megvalósítása: hitelesített domainről a FakeDriveren küldött üzenet
 * átmegy a hat kapun és `delivered` lesz. A PUBLIKUS `/v1/messages` felület az A1 packet
 * gazdája; ez a belső action, amire az is rá fog épülni — a gate megkerülése nélkül.
 */
final class SendMessage
{
    public function __construct(
        private readonly PreSendGate $gate,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): SendResult
    {
        $idempotencyKey = Arr::get($attributes, 'idempotency_key');

        // Idempotencia (golden rule 5): ugyanaz a kulcs ugyanazt a választ adja, nem küld újra.
        if ($idempotencyKey !== null) {
            $existing = Message::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return new SendResult($existing, $this->replayDecision($existing));
            }
        }

        $message = Message::create([
            'channel' => Arr::get($attributes, 'channel', 'transactional'),
            'from_email' => Arr::get($attributes, 'from_email'),
            'from_name' => Arr::get($attributes, 'from_name'),
            'subject' => Arr::get($attributes, 'subject'),
            'recipients' => Arr::wrap(Arr::get($attributes, 'to', [])),
            'tags' => Arr::get($attributes, 'tags', []),
            'variables' => Arr::get($attributes, 'variables', []),
            'headers' => Arr::get($attributes, 'headers', []),
            'options' => Arr::get($attributes, 'options', []),
            'deliverytime' => Arr::get($attributes, 'deliverytime'),
            'testmode' => (bool) Arr::get($attributes, 'testmode', false),
            'idempotency_key' => $idempotencyKey,
            'queued_at' => now(),
        ]);

        $decision = $this->gate->evaluate($message);

        if (! $decision->allowed) {
            $message->forceFill(['status' => 'rejected', 'reason' => $decision->reason])->save();

            $this->event($message, 'rejected', $decision->reason);

            return new SendResult($message, $decision);
        }

        $message->forceFill(['status' => 'accepted'])->save();
        $this->event($message, 'accepted');

        // A testmode naplóz, de NEM küld (Mailgun-paritás: o:testmode).
        if (! $message->testmode) {
            $this->dispatcher->dispatch($message);
        }

        return new SendResult($message->fresh(), $decision);
    }

    private function event(Message $message, string $type, ?string $reason = null): void
    {
        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => $type,
            'recipient' => $message->recipients[0] ?? null,
            'channel' => $message->channel,
            'reason' => $reason,
            'tags' => $message->tags,
            'variables' => $message->variables,
        ]);
    }

    /** Ismételt Idempotency-Key: az eredeti döntést tükrözzük vissza, új gate-futás nélkül. */
    private function replayDecision(Message $message): GateDecision
    {
        return $message->status === 'rejected'
            ? GateDecision::rejected($message->reason, [])
            : GateDecision::allowed([]);
    }
}
