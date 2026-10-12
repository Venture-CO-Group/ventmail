<?php

declare(strict_types=1);

namespace App\Modules\Template\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy feltöltött kép nyilvántartása (v1.1 · képfeltöltés). A bájtok az `AssetStore`
 * mögött élnek (ma a `public` diszken, később a ventVaultban — D10).
 */
final class TemplateAsset extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = ['driver', 'path', 'url', 'mime', 'size', 'width', 'height', 'original_name', 'created_by'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
