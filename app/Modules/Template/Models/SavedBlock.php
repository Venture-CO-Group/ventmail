<?php

declare(strict_types=1);

namespace App\Modules\Template\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy workspace MENTETT blokkja vagy szakasza (v1.1 · sablonkönyvtár).
 *
 * A `kind` mondja meg, hova illeszthető: `section` a levél gyökerébe, `block` egy oszlopba.
 * A tartalom ugyanaz a block-tree alak, és ugyanaz a validátor ellenőrzi, mint a sablont.
 */
final class SavedBlock extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $table = 'template_saved_blocks';

    protected $fillable = ['name', 'kind', 'block', 'created_by'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['block' => 'array'];
    }
}
