<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Tenant-izoláció global scope-pal (golden rule 6).
 *
 * Kézi `where('workspace_id', ...)` helyett MINDEN lekérdezés automatikusan szűkül az
 * aktuális workspace-re. Ha nincs kontextus, a scope NEM enged át semmit: a biztonságos
 * alapállapot az üres halmaz, nem a teljes tábla.
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(WorkspaceContext::class);

        if (! $context->hasWorkspace()) {
            // Fail-closed: kontextus nélkül nincs adat.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn('workspace_id'),
            $context->workspaceId()
        );
    }
}
