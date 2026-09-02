<?php

declare(strict_types=1);

namespace App\Modules\Sending\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateDecision;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): SendResult
    {
        // A bemenet ÉRVÉNYESSÉGE a sor létrehozása ELŐTT dől el: egy hibás kérés ne hozzon
        // létre perzisztens sort (korlátlan írás-erősítés lenne rossz kliensből), és a
        // hívó strukturált elutasítást kapjon, ne nyers adatbázis-hibát.
        $invalid = $this->rejectInvalidInput($attributes);

        if ($invalid !== null) {
            return $invalid;
        }

        $idempotencyKey = Arr::get($attributes, 'idempotency_key');

        // Idempotencia (golden rule 5): ugyanaz a kulcs ugyanazt a választ adja, nem küld újra.
        if ($idempotencyKey !== null) {
            $existing = Message::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return new SendResult($existing, $this->replayDecision($existing));
            }
        }

        $message = $this->createMessage($attributes, $idempotencyKey);

        $decision = $this->gate->evaluate($message);

        if (! $decision->allowed) {
            $message->forceFill(['status' => 'rejected', 'reason' => $decision->reason])->save();

            $this->event($message, 'rejected', $decision->reason);

            return new SendResult($message, $decision);
        }

        // A gate döntése az üzenetre kerül: a `gate_decided_at` teszi lehetővé, hogy egy
        // ütemezett küldésnél (deliverytime) a dispatcher újraértékeltethesse a gyorsan
        // változó kapukat — a közben érkezett leiratkozás különben nem számítana.
        $message->forceFill([
            'status' => 'accepted',
            'gate_decided_at' => now(),
        ])->save();
        $this->event($message, 'accepted');

        // A testmode naplóz, de NEM küld (Mailgun-paritás: o:testmode).
        if (! $message->testmode) {
            $this->dispatcher->dispatch($message);
        }

        return new SendResult($message->fresh(), $decision);
    }

    /**
     * Kizárólag azokat a hibákat fogja, amelyeket NEM lehet perzisztált üzenettel
     * kifejezni (ismeretlen csatorna → a DB check-constraint amúgy is elutasítaná).
     * A többi ellenőrzés a kapuké — ott a döntés auditált és trace-elhető.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rejectInvalidInput(array $attributes): ?SendResult
    {
        $channel = (string) Arr::get($attributes, 'channel', Channel::Transactional->value);

        if (Channel::tryFrom($channel) !== null) {
            return null;
        }

        $message = new Message([
            'channel' => $channel,
            'from_email' => Arr::get($attributes, 'from_email'),
            'recipients' => Arr::wrap(Arr::get($attributes, 'to', [])),
        ]);

        $decision = GateDecision::rejected(GateReason::InvalidRequest->value, [
            'recipient_sanity' => GateResult::reject(
                GateReason::InvalidRequest,
                'Ismeretlen csatorna; engedélyezett: '.implode(', ', Channel::values())
            ),
        ]);

        $this->audit->log('sending.gate.rejected', null, after: [
            'reason' => $decision->reason,
            'channel' => $channel,
            'gates' => $decision->trace(),
        ]);

        return new SendResult($message, $decision);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMessage(array $attributes, ?string $idempotencyKey): Message
    {
        $payload = [
            'channel' => Arr::get($attributes, 'channel', Channel::Transactional->value),
            'from_email' => strtolower(trim((string) Arr::get($attributes, 'from_email'))),
            'from_name' => Arr::get($attributes, 'from_name'),
            'subject' => Arr::get($attributes, 'subject'),
            'recipients' => array_map(
                fn ($recipient) => is_string($recipient) ? strtolower(trim($recipient)) : $recipient,
                Arr::wrap(Arr::get($attributes, 'to', []))
            ),
            'tags' => Arr::get($attributes, 'tags', []),
            'variables' => Arr::get($attributes, 'variables', []),
            'headers' => Arr::get($attributes, 'headers', []),
            'options' => Arr::get($attributes, 'options', []),
            'deliverytime' => Arr::get($attributes, 'deliverytime'),
            'testmode' => (bool) Arr::get($attributes, 'testmode', false),
            'idempotency_key' => $idempotencyKey,
            'queued_at' => now(),
        ];

        try {
            return Message::create($payload);
        } catch (UniqueConstraintViolationException) {
            // Versenyhelyzet: két párhuzamos, azonos kulcsú hívás közül mindkettő „nem
            // találtam"-ot olvasott. A DB döntött — a vesztes az ELSŐ üzenetet adja vissza,
            // nem 500-at (golden rule 5: ugyanaz a kulcs, ugyanaz a válasz).
            return Message::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
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
