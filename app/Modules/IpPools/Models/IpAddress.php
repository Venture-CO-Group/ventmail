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
        'daily_limit', 'sent_today', 'sent_on', 'reputation_score', 'status',
        'quarantined_at', 'quarantine_reason',
    ];

    protected function casts(): array
    {
        return [
            'warmup_started_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'sent_on' => 'date',
            'reputation_score' => 'float',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    /**
     * Kaphat-e MA forgalmat?
     *
     * A napi számláló DÁTUMHOZ kötött: ha a `sent_on` nem a mai nap, akkor ma még nem
     * küldtünk erről az IP-ről — nincs szükség éjféli reset-jobra, aminek az elmaradása
     * csendben megállítaná a küldést.
     */
    public function isAvailable(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $usedToday = $this->sent_on?->isToday() === true ? (int) $this->sent_today : 0;

        return $usedToday < (int) $this->daily_limit;
    }
}
