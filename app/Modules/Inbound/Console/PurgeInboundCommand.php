<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Console;

use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * A lejárt bejövő levelek takarítása (33. kritérium, golden rule 10).
 *
 * A bejövő levél IDEGEN TARTALOM: nem mi írtuk, és nem a mi jogalapunkon tároljuk. A rövid
 * retenció ezért nem kényelmi beállítás, hanem az adatminimalizálás gyakorlata.
 *
 * A FÁJLTÁRAT IS takarítja. Ha csak az adatbázis-sort törölnénk, a nyers levél és a
 * csatolmányok örökre a Wasabin maradnának — láthatatlanul, és pont azok a bájtok, amikben a
 * legtöbb személyes adat van.
 */
final class PurgeInboundCommand extends Command
{
    protected $signature = 'inbound:purge {--limit=500}';

    protected $description = 'A lejárt bejövő levelek és csatolmányaik törlése (adatbázis ÉS fájltár).';

    public function handle(WorkspaceContext $context, RawMailStore $store): int
    {
        $deleted = ['messages' => 0, 'attachments' => 0, 'files' => 0];

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $store, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    InboundMessage::query()
                        ->where($this->expired(...))
                        ->with('attachments')
                        ->limit((int) $this->option('limit'))
                        ->get()
                        ->each(function (InboundMessage $message) use ($store, &$deleted): void {
                            foreach ($message->attachments as $attachment) {
                                if (($attachment->storage_key ?? '') !== '') {
                                    $store->delete((string) $attachment->storage_key);
                                    $deleted['files']++;
                                }

                                $deleted['attachments']++;
                            }

                            if (($message->storage_key ?? '') !== '') {
                                $store->delete((string) $message->storage_key);
                                $deleted['files']++;
                            }

                            // A csatolmány-sorok a cascade-en mennek.
                            $message->delete();
                            $deleted['messages']++;
                        });
                }
            });
        } finally {
            $context->forget();
        }

        $this->info(sprintf(
            'Törölve — bejövő levél: %d, csatolmány: %d, fájl: %d',
            $deleted['messages'],
            $deleted['attachments'],
            $deleted['files'],
        ));

        return self::SUCCESS;
    }

    /**
     * „Lejárt" — a hiányzó lejárati idővel EGYÜTT.
     *
     * Az `expires_at`-ot a modell hookja tölti; a modellt megkerülő beszúrás `NULL`-t hagyna,
     * és a puszta `expires_at <= now()` azt SOSEM fogná meg.
     */
    private function expired(Builder $query): void
    {
        $days = (int) config('inbound.retention_days');

        $query
            ->where('expires_at', '<=', now())
            ->orWhere(fn (Builder $missing) => $missing
                ->whereNull('expires_at')
                ->where('received_at', '<=', now()->subDays($days)));
    }
}
