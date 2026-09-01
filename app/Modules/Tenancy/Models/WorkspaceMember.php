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
    protected $fillable = ['workspace_id', 'user_id', 'roles'];

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

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_filter((array) $this->roles));
    }
}
