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
 * A kontakt indexelése — ASZINKRON (49. kritérium).
 *
 * Miért job: a keresőindex SOHA nem lehet a mentés előfeltétele. Ha a Meili nem elérhető, a
 * kontakt akkor is mentődjön — egy keresőindex hibája nem lehet „a feliratkozás nem
 * sikerült" az űrlapon.
 *
 * A TÖRLÉST is ez viszi (`remove`), mert a GDPR-törlésnek a külső indexbe is el kell érnie.
 */
final class SyncContactToIndex implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $contactUuid,
        private readonly int $workspaceId,
        private readonly bool $remove = false,
    ) {
        // A JOB A COMMIT UTÁN indul.
        //
        // A `Contact::saved` hook TRANZAKCIÓN BELÜL dobja (az űrlap-beküldés egy
        // tranzakcióban ír kontaktot, tagságot és consentet), a `config/queue.php` pedig
        // mind a négy connectionön `after_commit => false`. Valódi Redis-queue-n a worker
        // így a commit ELŐTT futhat: a kontakt nem található, a job csendben visszatér, és
        // az űrlap-beiratkozás SOSEM kerül az indexbe.
        //
        // Ugyanaz a minta, mint a `ParseInboundMessage`, a `DeliverWebhook` és a
        // `ProcessValidationJob` esetében — explicit, mert a globális beállításra nem
        // hagyatkozunk.
        $this->afterCommit();
    }

    public function handle(ContactSearchIndex $index, WorkspaceContext $context): void
    {
        $workspace = Workspace::query()->withTrashed()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            // A TÖRÖLT kontaktot is meg kell találni: a GDPR-anonimizálás soft delete-tel
            // megy, és épp azt kell kivenni az indexből.
            $contact = Contact::query()->withTrashed()->where('uuid', $this->contactUuid)->first();

            if ($contact === null) {
                return;
            }

            $this->remove || $contact->trashed()
                ? $index->remove($contact)
                : $index->index($contact);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
