<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * A lejárt napló-melléktáblák takarítása (25. kritérium, golden rule 10).
 *
 * A `message_events` retencióját a Timescale `drop_chunks` végzi, az AGGREGÁTUM pedig
 * megmarad. Az itteni három tábla viszont hagyományos: mindegyik PII-t tárol (címzett-cím,
 * a fogadó válasza, a webhook-payload), tehát mindegyiknek lejárati ideje van.
 *
 * A LEJÁRAT NÉLKÜLI sor is takarodik. Az `expires_at`-ot a modell `creating` hookja tölti,
 * tehát a modellt megkerülő beszúrás (tömeges insert, migrációval betöltött sor)
 * `NULL`-t hagyna — és a puszta `expires_at <= now()` azt SOSEM fogná meg: a PII örökre
 * bent maradna. A golden rule 10 nem arról szól, hogy a szokásos úton beírt sor törlődik.
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

                    $deleted['smtp'] += SmtpTransaction::query()->where($this->expired(...))->delete();
                    $deleted['attempts'] += DeliveryAttempt::query()->where($this->expired(...))->delete();
                    $deleted['webhooks'] += WebhookDelivery::query()->where($this->expired(...))->delete();
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

    /**
     * „Lejárt" — a hiányzó lejárati idővel EGYÜTT.
     *
     * A `NULL` esetén a `created_at`-ra esünk vissza ugyanazzal a retenciós ablakkal: a sor
     * kora ismert, tehát nincs okunk örökre megtartani.
     */
    private function expired(Builder $query): void
    {
        $days = (int) config('sending.delivery_attempt_retention_days', 90);

        $query
            ->where('expires_at', '<=', now())
            ->orWhere(fn (Builder $missing) => $missing
                ->whereNull('expires_at')
                ->where('created_at', '<=', now()->subDays($days)));
    }
}
