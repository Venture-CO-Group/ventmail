<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Jobs;

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `forward(email)` — továbbítás a KIMENŐ motoron (32. kritérium).
 *
 * A továbbítás KIMENŐ KÜLDÉS, tehát ugyanaz a kapu érvényes rá, mint bármi másra: a
 * PreSendGate hat kapuja (golden rule 2). Nincs „belső" út a gate mellett — ha lenne, a
 * suppression-listás címre egy route-szabállyal bárki küldhetne.
 *
 * A FELADÓ MI VAGYUNK, nem az eredeti küldő. Ha az eredeti feladót tartanánk meg, a MI
 * IP-nkről menne ki egy idegen domain nevében írt levél: az SPF azonnal megbukna, és a
 * fogadó minket büntetne érte. Az eredeti feladó a `Reply-To`-ba kerül — így a válasz oda
 * megy, ahova az olvasó szánja.
 */
final class ForwardInboundToEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $messageUuid,
        private readonly string $target,
    ) {
        $this->afterCommit();
    }

    public function handle(WorkspaceContext $context, SendMessage $send): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $message = InboundMessage::query()->where('uuid', $this->messageUuid)->first();

            if ($message === null) {
                return;
            }

            $domain = Domain::query()->whereKey($message->domain_id)->first();

            if ($domain === null) {
                Log::warning('A továbbításhoz nincs feladó-domain.', ['inbound_message' => $this->messageUuid]);

                return;
            }

            $send([
                // TRANZAKCIÓS: a továbbítás nem marketing, és nem is tiltható le
                // marketing-leiratkozással (golden rule 4).
                'channel' => 'transactional',
                // A MI domainünk. A `noreply@` prefix szándékos: a válasz a `Reply-To`-ra
                // menjen, ne egy nem létező postafiókba.
                'from_email' => 'noreply@'.$domain->name,
                'from_name' => 'ventMail továbbítás',
                'to' => [$this->target],
                'subject' => (string) ($message->subject ?? '(tárgy nélkül)'),
                'html' => $message->body_html,
                'text' => $message->body_plain,
                'headers' => array_filter([
                    // Az EREDETI feladó: ide megy a válasz.
                    'Reply-To' => $message->sender,
                    // A továbbítás TÉNYE látszik a fejlécben — a fogadó lássa, honnan jött.
                    'X-VentMail-Forwarded-For' => $message->recipient,
                    'X-VentMail-Inbound-Id' => $message->uuid,
                ]),
                'tags' => ['inbound-forward'],
                // Az idempotencia-kulcs a BEJÖVŐ üzenet azonosítójából: ha a job kétszer fut
                // (worker-újraindulás), a levél akkor sem megy ki kétszer.
                'idempotency_key' => 'inbound-fwd-'.$message->uuid.'-'.substr(hash('sha256', $this->target), 0, 12),
            ]);
        } catch (Throwable $e) {
            // A gate elutasítása NEM hiba: az üzenet trace-e megmutatja, melyik kapu állt
            // meg. A kivételt viszont nem nyeljük el csendben.
            Log::warning('A bejövő levél továbbítása nem sikerült.', [
                'inbound_message' => $this->messageUuid,
                'error' => $e::class,
            ]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
