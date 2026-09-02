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

/**
 * Az esedékes, ütemezett üzenetek kiküldése (`o:deliverytime`).
 *
 * A KAPUK ÚJRA LEFUTNAK. A gate-döntésnek szavatossági ideje van: egy holnapra ütemezett
 * levélnél a közben érkezett leiratkozásnak, hard bounce-nak, kvóta-kimerülésnek vagy
 * domain-deverifikációnak számítania kell. Enélkül a tegnapi állapot szerint mennénk ki.
 */
final class DispatchScheduledCommand extends Command
{
    protected $signature = 'messages:dispatch-scheduled {--limit=500 : futásonként legfeljebb ennyi üzenet}';

    protected $description = 'Az esedékes ütemezett üzenetek kiküldése, a kapuk újraértékelésével.';

    public function handle(PreSendGate $gate, MessageDispatcher $dispatcher, WorkspaceContext $context): int
    {
        $sent = 0;
        $rejected = 0;

        // Tenantonként megyünk: a global scope-nak kontextus kell, és egy hibás workspace
        // nem állíthatja meg a többiét.
        Workspace::query()->each(function (Workspace $workspace) use ($gate, $dispatcher, $context, &$sent, &$rejected): void {
            $context->set($workspace);

            Message::query()
                ->where('status', 'scheduled')
                ->where('deliverytime', '<=', now())
                ->orderBy('deliverytime')
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(function (Message $message) use ($gate, $dispatcher, &$sent, &$rejected): void {
                    $decision = $gate->evaluate($message);

                    if (! $decision->allowed) {
                        $message->forceFill(['status' => 'rejected', 'reason' => $decision->reason])->save();

                        MessageEvent::create([
                            'message_uuid' => $message->uuid,
                            'type' => 'rejected',
                            'recipient' => $message->recipients[0] ?? null,
                            'channel' => $message->channel,
                            'reason' => $decision->reason,
                        ]);

                        $rejected++;

                        return;
                    }

                    $message->forceFill(['status' => 'accepted', 'gate_decided_at' => now()])->save();

                    if ($message->testmode) {
                        return;
                    }

                    $dispatcher->dispatch($message->fresh());
                    $sent++;
                });
        });

        $context->forget();

        $this->info("Kiküldve: {$sent}, elutasítva: {$rejected}");

        return self::SUCCESS;
    }
}
