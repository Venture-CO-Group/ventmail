<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Scopes\WorkspaceScope;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minden workspace-hez kötött entitás ezt használja: global scope + automatikus kitöltés.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model): void {
            if ($model->workspace_id !== null) {
                return;
            }

            $context = app(WorkspaceContext::class);

            if ($context->hasWorkspace()) {
                $model->workspace_id = $context->workspaceId();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
