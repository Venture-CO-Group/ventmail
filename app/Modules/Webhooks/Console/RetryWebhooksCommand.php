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
    protected $signature = 'webhooks:retry {--limit=200}';

    protected $description = 'Az esedékes (failed) webhook-kézbesítések újrapróbálása.';

    public function handle(WorkspaceContext $context): int
    {
        $queued = 0;

        try {
            Workspace::query()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$queued): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $due = WebhookDelivery::query()
                        ->where('status', 'failed')
                        ->whereNotNull('next_attempt_at')
                        ->where('next_attempt_at', '<=', now())
                        ->orderBy('next_attempt_at')
                        ->limit((int) $this->option('limit'))
                        ->get();

                    foreach ($due as $delivery) {
                        DeliverWebhook::dispatch((int) $workspace->getKey(), (string) $delivery->uuid);
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
