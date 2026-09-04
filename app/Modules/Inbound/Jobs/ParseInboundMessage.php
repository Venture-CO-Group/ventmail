<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Jobs;

use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Data\ParsedAttachment;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Services\ArchiveInspector;
use App\Modules\Inbound\Services\InboundMimeParser;
use App\Modules\Inbound\Services\MailAuthenticationReader;
use App\Modules\Inbound\Services\RouteEngine;
use App\Modules\Inbound\Services\StrippedTextExtractor;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A bejövő levél ÉRTELMEZÉSE — a fogadó folyamaton KÍVÜL (36. kritérium, ADR 0002).
 *
 * Ez a „sandbox": külön processz (queue-worker), saját időkorláttal, saját memória-kerettel.
 * Ha egy rosszindulatú MIME elviszi ezt a jobot, EGY üzenet vész el — a fogadó tovább veszi
 * át a többiek leveleit, és a többi worker sem áll meg.
 *
 * A `$tries = 1` szándékos: egy MIME, ami egyszer megfojtotta a parsert, másodszorra is meg
 * fogja. Az újrapróbálás itt nem javít, csak sokszorozza a kárt.
 */
final class ParseInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $messageUuid,
    ) {
        $this->afterCommit();
    }

    public function handle(
        WorkspaceContext $context,
        RawMailStore $store,
        InboundMimeParser $parser,
        StrippedTextExtractor $stripper,
        MailAuthenticationReader $authentication,
        VirusScanner $scanner,
        ArchiveInspector $archives,
        RouteEngine $routes,
    ): void {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $message = InboundMessage::query()->where('uuid', $this->messageUuid)->first();

            if ($message === null || $message->status !== 'received') {
                return;
            }

            $raw = $store->get((string) $message->storage_key);

            if ($raw === null) {
                $message->forceFill(['status' => 'failed', 'reason' => 'RAW_MISSING'])->save();

                return;
            }

            $parsed = $parser->parse($raw);

            $message->forceFill([
                'subject' => $parsed->subject,
                'message_id' => $parsed->messageId,
                'body_plain' => $parsed->bodyPlain,
                'body_html' => $parsed->bodyHtml,
                'stripped_text' => $stripper->extract($parsed->bodyPlain),
                'headers' => $parsed->headers,
                'spf_result' => $authentication->spf($parsed->headers),
                'dkim_result' => $authentication->dkim($parsed->headers),
                'dmarc_result' => $authentication->dmarc($parsed->headers),
                'spam_score' => $authentication->spamScore($parsed->headers),
                'status' => 'parsed',
                'parsed_at' => now(),
            ])->save();

            foreach ($parsed->attachments as $attachment) {
                $this->storeAttachment($message, $attachment, $store, $scanner, $archives);
            }

            // A ROUTE-MOTOR csak a sikeres parse után fut: a `match_header` a fejlécekre
            // illeszt, a `forward(url)` payload pedig a törzsből épül.
            $routes->run($message->fresh());
        } catch (Throwable $e) {
            // A MALFORMÁLT MIME NEM DÖNTI EL A WORKERT (36. kritérium). Az üzenet `failed`
            // lesz — látható, nem eltűnt —, és a kivétel a szerver-logba megy, nem a sorba.
            Log::warning('A bejövő levél értelmezése elhasalt.', [
                'inbound_message' => $this->messageUuid,
                'error' => $e::class,
            ]);

            InboundMessage::query()
                ->where('uuid', $this->messageUuid)
                ->update(['status' => 'failed', 'reason' => 'PARSE_FAILED']);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    private function storeAttachment(
        InboundMessage $message,
        ParsedAttachment $attachment,
        RawMailStore $store,
        VirusScanner $scanner,
        ArchiveInspector $archives,
    ): void {
        $row = InboundAttachment::query()->create([
            'inbound_message_id' => $message->getKey(),
            'filename' => $attachment->filename,
            'content_type' => $attachment->contentType,
            'size' => $attachment->size(),
            'sha256' => $attachment->sha256(),
        ]);

        // MÉRET: a plafon fölött a tartalmat EL SEM TESSZÜK. A metaadat marad, hogy az
        // ügyfél lássa, mi érkezett és miért nincs meg.
        if ($attachment->size() > (int) config('inbound.attachments.max_bytes')) {
            $row->forceFill([
                'scan_result' => 'rejected',
                'scan_detail' => 'A csatolmány mérete meghaladja a korlátot.',
            ])->save();

            return;
        }

        // ZIP-BOMBA: a kicsomagolás ELŐTT, az archívum saját könyveléséből.
        $inspection = $archives->inspect($attachment->contents);

        if ($inspection['bomb']) {
            $row->forceFill(['scan_result' => 'rejected', 'scan_detail' => $inspection['reason']])->save();

            return;
        }

        $scan = $scanner->scan($attachment->filename, $attachment->contents);

        // FERTŐZÖTT: a tartalom KARANTÉNBA megy (külön prefix), nem a letölthető helyre. A
        // törlés helyett a megőrzés a szándék: az ügyfélnek és a támogatásnak látnia kell,
        // mi érkezett — de letölteni nem lehet (`downloadable()`).
        $prefix = $scan->status === 'clean' ? 'attachments' : 'quarantine';

        $key = sprintf('inbound/%d/%s/%s', (int) $message->workspace_id, $prefix, (string) Str::uuid());

        $store->put($key, $attachment->contents);

        $row->forceFill([
            'storage_key' => $key,
            'scan_result' => $scan->status,
            'scan_detail' => $scan->detail,
        ])->save();
    }
}
