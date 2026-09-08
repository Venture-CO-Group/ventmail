<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Egy szegmens: szabály-halmaz, amiből a motor lekérdezést épít (49. kritérium).
 *
 * A definíció JSONB, és az ügyfél SOHA nem ír SQL-t. A mezőnevek zárt listából jönnek
 * (`SegmentEngine::FIELDS`), az operátorok szintén — enélkül a szegmens-szerkesztő egy
 * SQL-injektálási felület lenne a felületen, a legjobb szándék mellett is.
 */
final class Segment extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'description', 'definition', 'estimated_size', 'estimated_at'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'estimated_size' => 'integer',
            'estimated_at' => 'datetime',
        ];
    }
}
