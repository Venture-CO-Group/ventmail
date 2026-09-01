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
    /** Számláló, nem bool: egymásba ágyazott onPlatform() hívásnál a belső blokk
     *  nem kapcsolhatja vissza a külsőt a közepén. */
    private static int $platformWriteDepth = 0;

    public static function bootBelongsToWorkspaceOrPlatform(): void
    {
        static::addGlobalScope(new WorkspaceOrPlatformScope);

        static::creating(function ($model): void {
            if (self::isPlatformWrite()) {
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

            self::assertMayMutatePlatformRow($model);
        });

        // A platform-sor MINDEN tenant számára látszik — ha bármelyik törölhetné, a
        // leiratkozott címre onnantól mindenki küldhetne (golden rule 2/6).
        static::deleting(function ($model): void {
            self::assertMayMutatePlatformRow($model);
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
        self::$platformWriteDepth++;

        try {
            return $callback();
        } finally {
            self::$platformWriteDepth--;
        }
    }

    private static function isPlatformWrite(): bool
    {
        return self::$platformWriteDepth > 0;
    }

    private static function assertMayMutatePlatformRow(object $model): void
    {
        if ($model->workspace_id === null && ! self::isPlatformWrite()) {
            throw new RuntimeException(sprintf(
                '%s: platform-szintű sor csak ::onPlatform() hívásból módosítható vagy törölhető.',
                static::class
            ));
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
