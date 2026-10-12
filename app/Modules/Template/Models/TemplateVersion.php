<?php

declare(strict_types=1);

namespace App\Modules\Template\Models;

use App\Models\User;
use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A sablon egy MEGVÁLTOZTATHATATLAN pillanatképe (v1.1 · verziótörténet).
 *
 * A visszaállítás NEM írja át a régi verziót és nem törli az újabbakat: a visszaállított
 * tartalom ÚJ verzióként kerül a lánc végére. Így a „visszaállítottam, de mégis a mostani
 * kellett" is visszacsinálható — a történet nem veszít el semmit.
 */
final class TemplateVersion extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'template_id', 'number', 'name', 'subject', 'preheader', 'blocks', 'styles',
        'reason', 'restored_from', 'content_hash', 'created_by',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'styles' => 'array',
            'number' => 'integer',
            'restored_from' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A pillanatkép NEM módosítható — a történet attól történet, hogy nem írható át.
        self::updating(static function (): void {
            throw new LogicException('A sablon-verzió megváltoztathatatlan; új verziót kell írni.');
        });
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
