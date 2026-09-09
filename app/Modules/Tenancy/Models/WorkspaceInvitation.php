<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy nyitott (vagy már beváltott) tagsági meghívó.
 *
 * A `workspace_id`-t a global scope tölti — a meghívó TENANT-ADAT: hogy kit hívtak meg egy
 * ügynökséghez, az önmagában üzleti információ.
 *
 * A BEVÁLTÁS nem itt van, hanem a `RedeemInvitations` actionben: a bejelentkezés
 * pillanatában még NINCS workspace-kontextus (épp az dől el belőle), ezért az az út
 * szándékosan scope nélkül, EGYETLEN, cím + lejárat szerinti lekérdezéssel dolgozik.
 */
final class WorkspaceInvitation extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = ['email', 'roles', 'invited_by_user_id', 'expires_at'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_filter((array) $this->roles));
    }
}
