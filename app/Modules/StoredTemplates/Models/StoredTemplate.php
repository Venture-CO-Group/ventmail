<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tárolt sablon (spec 15.) — a Mailgun `template=` paraméterének párja.
 */
final class StoredTemplate extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'description'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StoredTemplateVersion::class)->orderByDesc('version');
    }

    public function activeVersion(): ?StoredTemplateVersion
    {
        return $this->versions()->where('is_active', true)->first();
    }

    public function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('version') + 1;
    }
}
