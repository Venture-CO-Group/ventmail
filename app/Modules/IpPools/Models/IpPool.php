<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Concerns\BelongsToWorkspaceOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IP-pool (spec 5.).
 *
 * `workspace_id = NULL` = megosztott (platform) pool: minden tenant használhatja, de
 * módosítani csak operátori úton lehet. A dedikált pool a tenanté.
 */
final class IpPool extends Model
{
    use Auditable, BelongsToWorkspaceOrPlatform, HasUuids;

    protected $fillable = ['name', 'type', 'channel'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * A poolhoz RENDELT domainek.
     *
     * Ez teszi lehetővé, hogy egy márka (vagy egy nagy ügyfél) saját IP-ről menjen: a
     * reputációja így nem keveredik a többiekével.
     */
    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'ip_pool_domain');
    }

    /** Használható-e ezen a csatornán? A marketing IP nem vihet tranzakciós forgalmat. */
    public function servesChannel(string $channel): bool
    {
        return $this->channel === null || $this->channel === $channel;
    }
}
