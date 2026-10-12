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
            Workspace::query()->onlyTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $store, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    TemplateAsset::query()->orderBy('id')->each(function (TemplateAsset $asset) use ($store, &$deleted): void {
                        $store->delete((string) $asset->path);
                        $asset->delete();
                        $deleted++;
                    });
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Törölt sablon-képek: {$deleted}");

        return self::SUCCESS;
    }
}
