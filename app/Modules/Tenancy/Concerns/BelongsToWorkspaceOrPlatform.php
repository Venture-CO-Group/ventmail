<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Scopes\WorkspaceOrPlatformScope;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Tenant- VAGY platform-szintű entitás (globális suppression, megosztott IP-pool).
 *
 * Írás: alapból mindig a kontextus workspace-ébe kerül. Platform-szintű sort KIZÁRÓLAG
 * explicit, szándéknyilvánító hívással lehet létrehozni (`::onPlatform()`), amit az
 * operátori útvonalak (SuperAdmin, rendszer-job) használnak — így egy sima tenant-kérés
 * véletlenül sem írhat mindenkire érvényes tiltást.
 */
trait BelongsToWorkspaceOrPlatform
{
    private static bool $platformWrite = false;

    public static function bootBelongsToWorkspaceOrPlatform(): void
    {
        static::addGlobalScope(new WorkspaceOrPlatformScope);

        static::creating(function ($model): void {
            if (self::$platformWrite) {
                $model->workspace_id = null;

                return;
            }

            $context = app(WorkspaceContext::class);

            if (! $context->hasWorkspace()) {
                throw new RuntimeException(sprintf(
                    '%s: workspace-kontextus nélkül csak platform-szintű írás megengedett '
                    .'(használd az ::onPlatform() hívást).',
                    static::class
                ));
            }

            if ($model->workspace_id !== null && (int) $model->workspace_id !== $context->workspaceId()) {
                throw new RuntimeException('Idegen workspace_id: tenant-határ sértése.');
            }

            $model->workspace_id = $context->workspaceId();
        });

        static::updating(function ($model): void {
            if ($model->isDirty('workspace_id')) {
                throw new RuntimeException('A workspace_id nem írható át.');
            }
        });
    }

    /**
     * Platform-szintű (minden tenantra érvényes) írás. Szándéknyilvánító hívás:
     * a globális suppression és a megosztott pool csak így jöhet létre.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function onPlatform(callable $callback): mixed
    {
        self::$platformWrite = true;

        try {
            return $callback();
        } finally {
            self::$platformWrite = false;
        }
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isPlatformWide(): bool
    {
        return $this->workspace_id === null;
    }
}
