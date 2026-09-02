<?php

declare(strict_types=1);

namespace App\Modules\Messages\Console;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Az esedékes, ütemezett üzenetek kiküldése (`o:deliverytime`).
 *
 * A KAPUK ÚJRA LEFUTNAK. A gate-döntésnek szavatossági ideje van: egy holnapra ütemezett
 * levélnél a közben érkezett leiratkozásnak, hard bounce-nak, kvóta-kimerülésnek vagy
 * domain-deverifikációnak számítania kell. Enélkül a tegnapi állapot szerint mennénk ki.
 *
 * HIBATŰRÉS: egyetlen üzenet hibája sem állíthatja meg a futást. A `MessageDispatcher`
 * driver-hibánál `deferred`-re állít, majd ÚJRA DOBJA a kivételt — enélkül a kapott hiba
 * az összes többi tenant esedékes levelét kizárta volna ebből a futásból.
 */
final class DispatchScheduledCommand extends Command
{
    protected $signature = 'messages:dispatch-scheduled {--limit=500 : futásonként legfeljebb ennyi üzenet}';

    protected $description = 'Az esedékes ütemezett üzenetek kiküldése, a kapuk újraértékelésével.';

    public function handle(PreSendGate $gate, MessageDispatcher $dispatcher, WorkspaceContext $context): int
    {
        $sent = 0;
        $rejected = 0;
        $failed = 0;

        try {
            // Tenantonként megyünk: a global scope-nak kontextus kell.
            //
            // `chunkById`, nem `each()`: az offset-lapozás rendezés nélkül Postgresen
            // sorokat hagyhat ki vagy duplázhat, és a kihagyott tenant esedékes levele
            // ebben a futásban nem ment volna ki.
            Workspace::query()->orderBy('id')->chunkById(100, function ($workspaces) use ($gate, $dispatcher, $context, &$sent, &$rejected, &$failed): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $due = Message::query()
                        ->where('status', 'scheduled')
                        ->where('deliverytime', '<=', now())
                        ->orderBy('deliverytime')
                        ->limit((int) $this->option('limit'))
                        ->get();

                    foreach ($due as $message) {
                        try {
                            $this->process($message, $gate, $dispatcher, $sent, $rejected);
                        } catch (Throwable $e) {
                            $failed++;

                            // A részlet a szerver-logba megy: az üzenet állapotát a
                            // dispatcher már `deferred`-re állította, tehát az
                            // újrapróbálásnak van hova visszatérnie.
                            Log::error('Ütemezett küldés hibára futott', [
                                'message_uuid' => $message->uuid,
                                'workspace_id' => $workspace->getKey(),
                                'exception' => $e::class,
                            ]);
                        }
                    }
                }
            });
        } finally {
            // A háttérfolyamat NEM hagyhat maga után tenant-kontextust — kivétel esetén sem.
            $context->forget();
        }

        $this->info("Kiküldve: {$sent}, elutasítva: {$rejected}, hibára futott: {$failed}");

        return self::SUCCESS;
    }

    private function process(
        Message $message,
        PreSendGate $gate,
        MessageDispatcher $dispatcher,
        int &$sent,
        int &$rejected,
    ): void {
        $decision = $gate->evaluate($message);

        if (! $decision->allowed) {
            $message->forceFill(['status' => 'rejected', 'reason' => $decision->reason])->save();

            // MINDEN címzett kap eseményt, ahogy a küldési úton is: egy elutasított
            // 1000-es batch trace-e különben egyetlen sor lenne.
            foreach ($message->recipients as $recipient) {
                MessageEvent::create([
                    'message_uuid' => $message->uuid,
                    'type' => 'rejected',
                    'recipient' => $recipient,
                    'channel' => $message->channel,
                    'reason' => $decision->reason,
                    'tags' => $message->tags,
                ]);
            }

            $rejected++;

            return;
        }

        $message->forceFill(['status' => 'accepted', 'gate_decided_at' => now()])->save();

        if ($message->testmode) {
            return;
        }

        $dispatcher->dispatch($message->fresh());
        $sent++;
    }
}
