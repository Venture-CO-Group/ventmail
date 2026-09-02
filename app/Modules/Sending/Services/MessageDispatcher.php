<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A `SendingDriver::send()` EGYETLEN hívója (golden rule 2).
 *
 * Garanciák:
 * 1. Csak `accepted` — tehát a PreSendGate kapuin átment — üzenetet küld.
 * 2. IDEMPOTENS: az `accepted → sending` átmenet feltételes UPDATE, amit az adatbázis
 *    egyszer enged. Kétszeri dispatch egy küldés, versenyhelyzetben is.
 * 3. NEM VESZÍT EL ÜZENETET: a `sending` állapot LÍZINGELT. Ha a driver kivételre fut vagy
 *    a process meghal, a lízing lejárta után az üzenet újraigényelhető — enélkül a levél
 *    csendben eltűnt volna, esemény és riasztás nélkül.
 */
final class MessageDispatcher
{
    /** Az az állapot, ahonnan a küldés indulhat: a PreSendGate kapuin átment üzenet. */
    private const DISPATCHABLE = 'accepted';

    /** Ezekbe az állapotokba a küldés MÁR eljutott — az ismételt hívás no-op. */
    private const TERMINAL = ['delivered', 'sent', 'bounced'];

    /** Hibára futott, de újrapróbálható állapotok. */
    private const RETRYABLE = ['deferred'];

    public function __construct(
        private readonly SendingDriver $driver,
        private readonly WorkspaceContext $context,
    ) {}

    public function dispatch(Message $message): void
    {
        // A gate MEGKERÜLÉSE hiba; az ISMÉTELT küldés viszont csak no-op.
        if (in_array($message->status, self::TERMINAL, true)) {
            return;
        }

        if ($message->status === 'sending' && ! $this->leaseExpired($message)) {
            return; // egy másik futás épp küldi
        }

        if (! in_array($message->status, [self::DISPATCHABLE, 'sending', ...self::RETRYABLE], true)) {
            throw new RuntimeException(sprintf(
                'A(z) %s üzenet állapota „%s" — csak a PreSendGate-en átment, `accepted` '
                .'üzenet küldhető (golden rule 2).',
                $message->uuid,
                $message->status
            ));
        }

        // A tenant-kontextus itt is kötelező: enélkül a scope 0 sort adna, és a küldés
        // NÉMÁN elmaradna. Jobban járunk hangos hibával.
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException(
                'A küldéshez workspace-kontextus kell — állítsd be a jobban a WorkspaceContextet.'
            );
        }

        // A gate-döntés SZAVATOSSÁGI IDEJE: ütemezett küldésnél a közben érkezett
        // leiratkozás vagy domain-deverifikáció különben nem számítana.
        if ($this->decisionStale($message)) {
            throw new RuntimeException(sprintf(
                'A(z) %s üzenet gate-döntése elavult (%s) — újraértékelés szükséges küldés előtt.',
                $message->uuid,
                (string) $message->gate_decided_at
            ));
        }

        if (! $this->claim($message)) {
            return; // más futás nyerte meg
        }

        try {
            $result = $this->driver->send($message);
        } catch (Throwable $e) {
            // A hiba RÖGZÜL: állapot + esemény, hogy az újrapróbálás elindulhasson és a
            // trace megmutassa, mi történt. A kivétel SZÖVEGE nem kerül a sorba
            // (titok-szivárgás ellen) — az a szerver-logba megy.
            $this->markDeferred($message, $e);

            throw $e;
        }

        $this->recordOutcome($message, $result);
    }

    private function claim(Message $message): bool
    {
        // Atomikus állapotátmenet ELOQUENTEN, tehát a tenant global scope-pal együtt.
        // (Nyers DB::table itt megkerülné az izolációt — golden rule 6.)
        $claimed = Message::query()
            ->whereKey($message->getKey())
            ->where(function ($query) {
                $query->where('status', self::DISPATCHABLE)
                    ->orWhereIn('status', self::RETRYABLE)
                    ->orWhere(fn ($stale) => $stale->where('status', 'sending')
                        ->where('sending_started_at', '<=', now()->subSeconds($this->leaseSeconds())));
            })
            ->update([
                'status' => 'sending',
                'sending_started_at' => now(),
                'attempts' => $message->attempts + 1,
                'updated_at' => now(),
            ]);

        return $claimed > 0;
    }

    private function recordOutcome(Message $message, DispatchResult $result): void
    {
        // A driver „elfogadta" NEM azonos a „kézbesítve"-vel: egy valódi MTA 2xx-e csak
        // annyit jelent, hogy sorba állt. A `delivered` a driver visszaigazolásából jön
        // (a FakeDriver szimulálja az MTA visszajelzését is).
        $status = match (true) {
            ! $result->accepted => 'deferred',
            $result->delivered => 'delivered',
            default => 'sent',
        };

        $message->forceFill([
            'status' => $status,
            'reason' => $result->accepted ? null : 'DRIVER_REJECTED',
            'sent_at' => now(),
        ])->save();

        $this->events($message, $status === 'delivered' ? 'delivered' : ($status === 'sent' ? 'accepted' : 'deferred'), [
            'provider_message_id' => $result->providerMessageId,
        ]);
    }

    private function markDeferred(Message $message, Throwable $e): void
    {
        Log::error('A küldés meghiúsult a drivernél', [
            'message_uuid' => $message->uuid,
            'driver' => $this->driver->capabilities()->name,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        $message->forceFill(['status' => 'deferred', 'reason' => 'DRIVER_ERROR'])->save();

        $this->events($message, 'deferred', ['attempt' => $message->attempts]);
    }

    /** @param array<string, mixed> $payload */
    private function events(Message $message, string $type, array $payload = []): void
    {
        // MINDEN címzett kap eseményt: egyetlen sor batch esetén 999 címzettről nem tudna
        // semmit az A3 trace és a kézbesítési statisztika.
        foreach ($message->recipients as $recipient) {
            MessageEvent::create([
                'message_uuid' => $message->uuid,
                'type' => $type,
                'recipient' => $recipient,
                'channel' => $message->channel,
                'reason' => $message->reason,
                'tags' => $message->tags,
                'payload' => $payload,
            ]);
        }
    }

    private function decisionStale(Message $message): bool
    {
        $maxAge = (int) config('sending.decision_max_age_seconds');

        return $message->gate_decided_at !== null
            && $message->gate_decided_at->lt(now()->subSeconds($maxAge));
    }

    private function leaseExpired(Message $message): bool
    {
        return $message->sending_started_at === null
            || $message->sending_started_at->lte(now()->subSeconds($this->leaseSeconds()));
    }

    private function leaseSeconds(): int
    {
        return (int) config('sending.lease_seconds');
    }
}
