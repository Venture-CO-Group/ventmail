<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Tenant-egység. Sub-account: egy parent alá tetszőleges számú child tartozhat,
 * a child pedig LEGFELJEBB egy parenthez (spec 3.2).
 *
 * A workspace maga NEM használ WorkspaceScope-ot (ő maga a scope alanya); a hozzáférést
 * a tagság (`workspace_members`) és a parent-kapcsolat dönti el.
 */
final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'plan', 'settings'];

    protected static function newFactory(): Factory
    {
        return WorkspaceFactory::new();
    }

    /** A `uuid` a publikus azonosító, az `id` marad a belső kulcs. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members');
    }

    /** A közvetlen gyerek-workspace-ek (sub-accountok). */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'workspace_parents', 'parent_workspace_id', 'child_workspace_id')
            ->withPivot('permissions');
    }

    /** A szülő workspace, ha ez egy sub-account. */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'workspace_parents', 'child_workspace_id', 'parent_workspace_id')
            ->withPivot('permissions');
    }

    public function parent(): ?self
    {
        return $this->parents()->first();
    }

    public function isChildOf(self $workspace): bool
    {
        return $this->parents()->whereKey($workspace->getKey())->exists();
    }

    /**
     * A parent által ELÉRHETŐ workspace-ek: önmaga + a gyerekei.
     * A gyerekek egymást SOHA nem látják (izoláció) — ezt a WorkspaceContext kényszeríti ki.
     *
     * @return Collection<int, int>
     */
    public function accessibleWorkspaceIds(): Collection
    {
        return $this->children()->pluck('workspaces.id')->push($this->getKey())->unique()->values();
    }
}
