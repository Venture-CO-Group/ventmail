<?php

declare(strict_types=1);

namespace App\Modules\Validation\Console;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Validation\Models\ValidationJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * A lejárt ellenőrzési futások takarítása (golden rule 10).
 *
 * A feltöltött címlista SZEMÉLYES ADAT — ráadásul jellemzően az ügyfél TELJES címlistája,
 * egy fájlban. Ennél érzékenyebb adat kevés van a rendszerben, és a megőrzésére az
 * ellenőrzés után nincs jogalapunk.
 */
final class PurgeValidationCommand extends Command
{
    protected $signature = 'validation:purge';

    protected $description = 'A lejárt címellenőrzési futások és eredményeik törlése.';

    public function handle(WorkspaceContext $context): int
    {
        $deleted = 0;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$deleted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    // Az eredmény-sorok a cascade-en mennek.
                    $deleted += ValidationJob::query()->where($this->expired(...))->delete();
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Törölve — ellenőrzési futás: {$deleted}");

        return self::SUCCESS;
    }

    /** „Lejárt" — a hiányzó lejárati idővel EGYÜTT (a modellt megkerülő beszúrás `NULL`-t hagy). */
    private function expired(Builder $query): void
    {
        $days = (int) config('validation.bulk.retention_days');

        $query
            ->where('expires_at', '<=', now())
            ->orWhere(fn (Builder $missing) => $missing
                ->whereNull('expires_at')
                ->where('created_at', '<=', now()->subDays($days)));
    }
}
