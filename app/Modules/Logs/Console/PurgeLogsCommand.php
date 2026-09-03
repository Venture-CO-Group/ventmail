<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * A lejárt napló-melléktáblák takarítása (25. kritérium, golden rule 10).
 *
 * A `message_events` retencióját a Timescale `drop_chunks` végzi, az AGGREGÁTUM pedig
 * megmarad. Az itteni három tábla viszont hagyományos: mindegyik PII-t tárol (címzett-cím,
 * a fogadó válasza, a webhook-payload), tehát mindegyiknek lejárati ideje van.
 *
 * TENANTONKÉNT megy, kontextussal — nem a global scope kikapcsolásával (golden rule 6).
 * A `withTrashed` azért kell, mert a soft-deleted tenant adatait is takarítani kell: a
 * cascade csak hard delete-re fut le.
 */
final class PurgeLogsCommand extends Command
{
    protected $signature = 'logs:purge';

    protected $description = 'A lejárt SMTP-párbeszédek, kézbesítési kísérletek és webhook-sorok törlése.';

    public function handle(WorkspaceContext $context): int
    {
        $deleted = ['smtp' => 0, 'attempts' => 0, 'webhooks' => 0];

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $deleted['smtp'] += SmtpTransaction::query()->where('expires_at', '<=', now())->delete();
                    $deleted['attempts'] += DeliveryAttempt::query()->where('expires_at', '<=', now())->delete();
                    $deleted['webhooks'] += WebhookDelivery::query()->where('expires_at', '<=', now())->delete();
                }
            });
        } finally {
            $context->forget();
        }

        $this->info(sprintf(
            'Törölve — SMTP-párbeszéd: %d, kísérlet: %d, webhook-sor: %d',
            $deleted['smtp'],
            $deleted['attempts'],
            $deleted['webhooks']
        ));

        return self::SUCCESS;
    }
}
