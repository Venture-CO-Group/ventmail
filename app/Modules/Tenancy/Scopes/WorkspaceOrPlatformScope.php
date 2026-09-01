<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Olyan táblákhoz, ahol a `workspace_id = NULL` PLATFORM-szintű sort jelent, amely MINDEN
 * tenantra érvényes: globális suppression (spec 6.2) és megosztott IP-pool (spec 5.).
 *
 * A tenant a sajátját ÉS a platform-sorokat látja — de másik tenant sorát soha. Kontextus
 * nélkül csak a platform-sorok látszanak, tenant-adat nem szivárog (fail-closed marad).
 */
final class WorkspaceOrPlatformScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(WorkspaceContext::class);
        $column = $model->qualifyColumn('workspace_id');

        if (! $context->hasWorkspace()) {
            $builder->whereNull($column);

            return;
        }

        $builder->where(function (Builder $query) use ($column, $context): void {
            $query->where($column, $context->workspaceId())->orWhereNull($column);
        });
    }
}
