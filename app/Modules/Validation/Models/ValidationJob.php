<?php

declare(strict_types=1);

namespace App\Modules\Validation\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Egy BULK címellenőrzési futás (41. kritérium). */
final class ValidationJob extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = ['name', 'status', 'reason', 'total', 'processed', 'started_at', 'finished_at', 'expires_at'];

    protected $attributes = ['status' => 'pending', 'total' => 0, 'processed' => 0];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $job): void {
            // A feltöltött címlista SZEMÉLYES ADAT: a retenció itt nem opció.
            $job->expires_at ??= now()->addDays((int) config('validation.bulk.retention_days'));
        });
    }

    public function results(): HasMany
    {
        return $this->hasMany(ValidationResult::class);
    }
}
