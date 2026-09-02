<?php

declare(strict_types=1);

namespace App\Modules\Messages\Console;

use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A lejárt nyers MIME-levelek törlése (golden rule 10, GDPR Art. 17).
 *
 * A nyers levél a teljes levéltest, csatolmányostul — a rendszer legsűrűbb PII-tárolója.
 * A `messages` és a `message_events` retenciója önmagában nem elég, ha a levél MAGA
 * határidő nélkül megmarad.
 *
 * A takarítás TENANTONKÉNT megy, kontextussal — nem a global scope kikapcsolásával
 * (golden rule 6). Egy karbantartó parancs kedvéért nem nyitunk izolációs kiskaput; a
 * lejárat feltétele tenantonként ugyanaz.
 */
final class PurgeRawMimeCommand extends Command
{
    protected $signature = 'messages:purge-raw-mime';

    protected $description = 'A retenciós határidőn túli nyers MIME-levelek törlése.';

    public function handle(WorkspaceContext $context): int
    {
        $deleted = 0;

        try {
            // `withTrashed`: a soft-deleted tenant levelei különben HATÁRIDŐ NÉLKÜL
            // bennmaradnának (a `cascadeOnDelete` csak hard delete-re fut le) — pont az,
            // amit a golden rule 10 tilt.
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $deleted += RawMimeMessage::query()
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())
                        ->delete();
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Törölt nyers MIME-levél: {$deleted}");

        return self::SUCCESS;
    }
}
