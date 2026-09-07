<?php

declare(strict_types=1);

namespace App\Modules\Validation\Models;

use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Egy cím ellenőrzésének eredménye a bulk futásban. */
final class ValidationResult extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['validation_job_id', 'address', 'result', 'risk', 'reasons', 'suggestion'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'risk' => 'float',
            'redacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Törlési kérés után ebbe a táblába sem kerülhet a cím: a bulk futás hetekkel
        // később is beírhatná. A TÉNY (minősítés) marad, a SZEMÉLY nem.
        self::creating(function (self $result): void {
            if (ErasedRecipient::erased((string) $result->address)) {
                $result->address = '(redaktált)';
                $result->suggestion = null;
                $result->redacted_at = now();
            }
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ValidationJob::class, 'validation_job_id');
    }
}
