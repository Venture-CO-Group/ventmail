<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * Az esedékes webhook-újrapróbálások elindítása.
 *
 * Miért nem a queue `release()`-e csinálja: mert akkor az újrapróbálás a sor belügye lenne,
 * és nem látszana sehol. Így a `webhook_deliveries` sor MUTATJA, mikor jön a következő
 * kísérlet — ez az, amit az ügyfél a felületen lát, amikor a végpontja épp nem működik.
 */
final class RetryWebhooksCommand extends Command
{
    /**
     * A LÍZING hossza: eddig nem nyúl hozzá senki más ehhez a sorhoz.
     *
     * Enélkül a percenként futó parancs torlódásnál (worker-újraindulás, 200 esedékes sor)
     * ugyanazt a kézbesítést percenként újra sorba tenné — az ügyfél végpontja többször
     * kapná meg ugyanazt az eseményt, az `attempt` számláló pedig versenyezne.
     */
    private const LEASE_SECONDS = 300;

    protected $signature = 'webhooks:retry {--limit=200}';

    protected $description = 'Az esedékes webhook-kézbesítések újrapróbálása.';

    public function handle(WorkspaceContext $context): int
    {
        $queued = 0;

        try {
            Workspace::query()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$queued): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    // A `pending` IS ide tartozik: ha a job elhalt, mielőtt a HTTP-hívásig
                    // jutott volna (worker-újraindulás, Redis-kiesés), a sor `pending`-en
                    // ragadna — és a 28. kritérium retry-ígérete csendben megszűnne.
                    $due = WebhookDelivery::query()
                        ->whereIn('status', ['pending', 'failed'])
                        ->whereNotNull('next_attempt_at')
                        ->where('next_attempt_at', '<=', now())
                        ->orderBy('next_attempt_at')
                        ->limit((int) $this->option('limit'))
                        ->pluck('uuid');

                    foreach ($due as $uuid) {
                        // CLAIM: feltételes UPDATE. Ha két futás versenyez ugyanazon a
                        // soron, csak az egyik kap 1-et vissza — a másik továbblép.
                        $claimed = WebhookDelivery::query()
                            ->where('uuid', $uuid)
                            ->where('next_attempt_at', '<=', now())
                            ->update(['next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS)]);

                        if ($claimed !== 1) {
                            continue;
                        }

                        DeliverWebhook::dispatch((int) $workspace->getKey(), (string) $uuid);
                        $queued++;
                    }
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Újrapróbálásra sorba tett kézbesítés: {$queued}");

        return self::SUCCESS;
    }
}
