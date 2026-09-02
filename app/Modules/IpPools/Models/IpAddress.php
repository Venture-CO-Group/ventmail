<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Models;

use App\Modules\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy küldő IP és a warmup-állapota (spec 5.).
 *
 * Szándékosan NEM workspace-hez kötött: az IP a POOL-hoz tartozik, a pool pedig lehet
 * platform-szintű (megosztott). A tenant-izolációt a pool adja.
 */
final class IpAddress extends Model
{
    use Auditable;

    protected $table = 'ip_addresses';

    protected $fillable = [
        'ip_pool_id', 'address', 'hostname', 'warmup_state', 'warmup_started_at',
        'daily_limit', 'sent_today', 'reputation_score', 'status',
    ];

    protected function casts(): array
    {
        return [
            'warmup_started_at' => 'datetime',
            'reputation_score' => 'float',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active' && $this->sent_today < $this->daily_limit;
    }
}
