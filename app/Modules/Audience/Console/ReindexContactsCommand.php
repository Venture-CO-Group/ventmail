<?php

declare(strict_types=1);

namespace App\Modules\Audience\Console;

use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * A kontakt-kereső index ÚJRAÉPÍTÉSE (49. kritérium).
 *
 * Két docblock hivatkozott erre a parancsra, mielőtt létezett volna — a review meg is
 * találta. Nem díszlet: az indexelés ASZINKRON és „legjobb szándék" alapon fut (a keresés
 * sosem lehet a mentés előfeltétele), tehát egy Meili-kimaradás alatt keletkezett kontaktok
 * kimaradnak az indexből. Enélkül a helyreállítás csak minden kontakt megnyitása és mentése
 * lett volna.
 *
 * A TÖRLÖTT kontaktokat is végigjárja, de KIVESZI őket az indexből: a GDPR-anonimizálás
 * soft delete-tel megy, és egy újraépítés nem hozhatja vissza a törölt címeket egy külső
 * rendszerbe.
 */
final class ReindexContactsCommand extends Command
{
    protected $signature = 'audience:reindex {--workspace= : csak ez a workspace (uuid vagy slug)}';

    protected $description = 'A kontakt-kereső index újraépítése.';

    public function handle(ContactSearchIndex $index, WorkspaceContext $context): int
    {
        $indexed = 0;
        $removed = 0;

        try {
            $this->workspaces()->chunkById(50, function ($workspaces) use ($context, $index, &$indexed, &$removed): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    Contact::query()
                        ->withTrashed()
                        ->orderBy('id')
                        ->chunkById(500, function ($contacts) use ($index, &$indexed, &$removed): void {
                            foreach ($contacts as $contact) {
                                if ($contact->trashed()) {
                                    $index->remove($contact);
                                    $removed++;

                                    continue;
                                }

                                $index->index($contact);
                                $indexed++;
                            }
                        });
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Indexelve: {$indexed}, eltávolítva: {$removed}");

        return self::SUCCESS;
    }

    /** @return Builder<Workspace> */
    private function workspaces()
    {
        $only = (string) $this->option('workspace');

        return Workspace::query()
            ->when($only !== '', fn ($query) => $query->where(
                fn ($inner) => $inner->where('uuid', $only)->orWhere('slug', $only)
            ))
            ->orderBy('id');
    }
}
