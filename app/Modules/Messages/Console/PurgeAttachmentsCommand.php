<?php

declare(strict_types=1);

namespace App\Modules\Messages\Console;

use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\MessageAttachment;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A kimenő csatolmány-bájtok takarítása (H123, golden rule 10, GDPR Art. 5(1)(e)).
 *
 * Az elsődleges törlés a `Message` modell-hookja: a lezárt üzenet bájtjai azonnal mennek.
 * Ez a parancs a HÁLÓ: törli
 *
 * 1. a lejártat (`expires_at`) — akkor is, ha a levél még nem zárult le (pl. elakadt a
 *    sorban): a bájtok megőrzésének felső határa nem függhet egy sosem érkező állapottól;
 * 2. a lezárt üzenetekét — ha az állapotváltás a modellt megkerülve történt.
 *
 * TENANTONKÉNT, kontextussal (golden rule 6) — mint a `messages:purge-raw-mime`.
 */
final class PurgeAttachmentsCommand extends Command
{
    protected $signature = 'messages:purge-attachments';

    protected $description = 'A lejárt és a lezárt üzenetek kimenő csatolmány-bájtjainak törlése.';

    public function handle(WorkspaceContext $context): int
    {
        $deleted = 0;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $deleted += MessageAttachment::query()
                        ->where(fn ($query) => $query
                            ->where('expires_at', '<=', now())
                            ->orWhereIn('message_uuid', Message::query()
                                ->select('uuid')
                                ->whereIn('status', Message::ATTACHMENT_RELEASING_STATUSES)))
                        ->delete();
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Törölt csatolmány: {$deleted}");

        return self::SUCCESS;
    }
}
