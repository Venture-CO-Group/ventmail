<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Címke a kontaktokon — a szegmentálás legegyszerűbb dimenziója (49. kritérium). */
final class ContactTag extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = ['name'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function contacts(): BelongsToMany
    {
        $context = app(WorkspaceContext::class);

        return $this->belongsToMany(Contact::class, 'contact_tag_assignments')
            ->withPivotValue(
                'workspace_id',
                $context->hasWorkspace() ? $context->workspaceId() : (int) $this->workspace_id
            )
            ->withTimestamps();
    }
}
