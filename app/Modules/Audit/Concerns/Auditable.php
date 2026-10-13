<?php

declare(strict_types=1);

namespace App\Modules\Audit\Concerns;

use App\Modules\Audit\Services\AuditLogger;

/**
 * Automatikus audit minden admin-műveletre (golden rule 11).
 *
 * A modell csak azt mondja meg, hogy auditált — a MIT és HOGYAN az AuditLoggeré, hogy a
 * titok-szűrés egyetlen helyen legyen kikényszerítve.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model): void {
            app(AuditLogger::class)->log('created', $model, after: $model->getAttributes());
        });

        static::updated(function ($model): void {
            // Egy modell a GYAKORI, saját tömör audit-sorral naplózott írásnál (pl. a
            // sablonszerkesztő autosave-je: `template.autosaved`) kérheti a teljes diff
            // kihagyását — a tényt ilyenkor a hívó naplózza, nem marad nyom nélkül.
            if (method_exists($model, 'skipsUpdateAudit') && $model->skipsUpdateAudit()) {
                return;
            }

            $changes = $model->getChanges();
            $before = array_intersect_key($model->getOriginal(), $changes);

            app(AuditLogger::class)->log('updated', $model, before: $before, after: $changes);
        });

        static::deleted(function ($model): void {
            app(AuditLogger::class)->log('deleted', $model, before: $model->getAttributes());
        });
    }
}
