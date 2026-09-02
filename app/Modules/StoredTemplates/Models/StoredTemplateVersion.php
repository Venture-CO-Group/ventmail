<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sablon egy verziója.
 *
 * A verziók MEGVÁLTOZTATHATATLANOK: ami egyszer kimehetett egy levélben, azt utólag nem
 * írjuk át — különben nem lehetne megmondani, mit kapott a címzett. Módosítás = új verzió.
 */
final class StoredTemplateVersion extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = ['stored_template_id', 'version', 'body', 'body_text', 'engine', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $version): void {
            // Csak az aktiválás állítható; a TARTALOM nem.
            if (array_diff(array_keys($version->getDirty()), ['is_active', 'updated_at']) !== []) {
                throw new \RuntimeException(
                    'A sablon-verzió tartalma nem írható át — módosítás esetén új verzió keletkezik.'
                );
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(StoredTemplate::class, 'stored_template_id');
    }
}
