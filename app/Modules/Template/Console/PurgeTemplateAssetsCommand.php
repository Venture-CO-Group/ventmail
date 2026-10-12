<?php

declare(strict_types=1);

namespace App\Modules\Template\Console;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A TÖRÖLT workspace-ek sablon-képeinek takarítása (SS5).
 *
 * A képek publikus URL-en élnek; egy megszűnt tenant képei ne maradjanak elérhetők. A
 * workspace soft delete-jének nincs hookja, ezért ütemezett takarítás fut (`routes/console`).
 */
final class PurgeTemplateAssetsCommand extends Command
{
    protected $signature = 'templates:purge-assets';

    protected $description = 'A törölt workspace-ek sablon-képeinek törlése (fájltár és nyilvántartás).';

    public function handle(WorkspaceContext $context, AssetStore $store): int
    {
        $deleted = 0;
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            // TÜRELMI IDŐ (S3): a soft delete visszavonható; a képek csak a türelmi idő
            // letelte után törlődnek, addig a visszaállított workspace sablonjai épek.
            $cutoff = now()->subDays((int) config('template.assets.purge_grace_days', 30));

            Workspace::query()->onlyTrashed()->where('deleted_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $store, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    // `lazyById` (S3): az offset-lapozás törlés közben átugrana sorokat.
                    foreach (TemplateAsset::query()->lazyById(200) as $asset) {
                        $store->delete((string) $asset->path);
                        $asset->delete();
                        $deleted++;
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Törölt sablon-képek: {$deleted}");

        return self::SUCCESS;
    }
}
