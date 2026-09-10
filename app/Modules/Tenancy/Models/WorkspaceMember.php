<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-tagság EGYETLEN forrása (golden rule 1): a ventSSO tokenje sosem dönt
 * workspace-hozzáférésről, csak szerepet ad.
 */
final class WorkspaceMember extends Model
{
    // A workspace_id csak a Tenancy modul sanctionált útján (AssignWorkspaceRoles) állítható:
    // egy tagkezelő endpoint tömeges hozzárendeléssel idegen workspace-be írhatna tagságot.
    protected $fillable = ['workspace_id', 'user_id', 'roles', 'source'];

    /** A PARENT tulajdonosi köréből TÜKRÖZÖTT tagság jelölése. */
    public const SOURCE_PARENT_OWNER = 'parent_owner_sync';

    /** @var list<string> */
    protected $hidden = [];

    protected function casts(): array
    {
        return ['roles' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A tükrözésből származó (tehát a parent felől visszavonható) tagság? */
    public function isMirroredFromParent(): bool
    {
        return $this->source === self::SOURCE_PARENT_OWNER;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_filter((array) $this->roles));
    }
}
