<?php

declare(strict_types=1);

namespace App\Modules\Audience\Jobs;

use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Egy import-köteg kontaktjainak KÖTEGES indexelése.
 *
 * Az import kötegelt `INSERT`-je megkerüli a modell-eseményeket (különben 300 000 külön
 * `SyncContactToIndex` job és 300 000 audit-sor lenne), ezért az indexelést ez a job végzi,
 * chunkonként egyszer. A keresőindex itt is csak „legjobb szándék": egy Meili-kimaradás nem
 * buktathatja az importot, a hiányt az `audience:reindex` pótolja.
 */
final class IndexImportedContacts implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $contactUuids */
    public function __construct(
        private readonly int $workspaceId,
        private readonly array $contactUuids,
    ) {
        $this->afterCommit();
        $this->onConnection(config('audience.import.connection'));
        $this->onQueue('imports');
    }

    public function handle(ContactSearchIndex $index, WorkspaceContext $context): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $index->indexMany(Contact::query()->whereIn('uuid', $this->contactUuids)->get());
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
