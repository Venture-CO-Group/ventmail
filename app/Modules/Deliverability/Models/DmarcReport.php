<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Models;

use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Egy BETÖLTÖTT DMARC RUA jelentés (44. kritérium).
 *
 * Tenant-adat, `workspace_id`-vel: a jelentés az ÜGYFÉL domainjéről szól, és a benne lévő
 * forrás-IP-k az ő küldési képét rajzolják ki. Ez a `blocklist_checks`-szel szembeni tudatos
 * különbség — az a MI IP-nkről szól, tehát platform-adat.
 */
final class DmarcReport extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'domain_id', 'org_name', 'report_id', 'body_hash', 'policy',
        'range_start', 'range_end', 'total', 'passed', 'failed', 'expires_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'range_start' => 'datetime',
            'range_end' => 'datetime',
            'expires_at' => 'datetime',
            'total' => 'integer',
            'passed' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DmarcReportRow::class);
    }

    /**
     * Az IGAZODÁSI arány — a jelentés egyetlen legfontosabb száma.
     *
     * Ha ez tartósan 100% alatt van, valaki a domain nevében küld úgy, hogy nem tud (vagy nem
     * akar) igazodni: vagy egy elfelejtett saját rendszer, vagy egy hamisító.
     */
    public function passRate(): float
    {
        return $this->total === 0 ? 0.0 : round($this->passed / $this->total * 100, 2);
    }
}
