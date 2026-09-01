<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Scopes\WorkspaceScope;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Minden workspace-hez kötött entitás ezt használja.
 *
 * A `WorkspaceScope` csak OLVASÁSRA (és update/delete-re) szűkít — INSERT-re nem fut.
 * Ezért az írás irányú tenant-határt itt kell őrizni: a `workspace_id` mindig a
 * kontextusból jön, és eltérő értékkel a mentés HANGOSAN elszáll. Enélkül egy
 * `Model::create(['workspace_id' => ...])` idegen tenant adatterébe írna (golden rule 6).
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model): void {
            $context = app(WorkspaceContext::class);

            if (! $context->hasWorkspace()) {
                throw new RuntimeException(sprintf(
                    'Workspace-kontextus nélkül nem hozható létre %s. A tenant-kontextust a '
                    .'ResolveWorkspace middleware (vagy a job-ban explicit beállítás) adja.',
                    static::class
                ));
            }

            $model->workspace_id = self::assertOwnWorkspace($model->workspace_id, $context);
        });

        static::updating(function ($model): void {
            if (! $model->isDirty('workspace_id')) {
                return;
            }

            // A sor átmozgatása egy másik tenantba sosem legitim művelet.
            throw new RuntimeException(sprintf(
                'A %s workspace_id-je nem írható át (%s → %s): a sor nem vándorolhat másik tenantba.',
                static::class,
                (string) $model->getOriginal('workspace_id'),
                (string) $model->workspace_id
            ));
        });
    }

    private static function assertOwnWorkspace(mixed $requested, WorkspaceContext $context): int
    {
        $current = $context->workspaceId();

        if ($requested !== null && (int) $requested !== $current) {
            throw new RuntimeException(sprintf(
                'Idegen workspace_id (%s) az aktuális kontextusban (%s): tenant-határ sértése.',
                (string) $requested,
                (string) $current
            ));
        }

        return $current;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
