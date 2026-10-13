<?php

declare(strict_types=1);

namespace App\Modules\Logs\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Domainenkénti napi számláló (v1.1 · 7. modul, D175) — a Platform-statisztika forrása.
 *
 * A nyers eseményekkel ellentétben ez a retenció után is MEGMARAD (aggregátum, PII nélkül).
 * Írni KIZÁRÓLAG a `DomainStatsRecorder` ír bele (sor-szintű `+1` upsert).
 */
final class DomainDailyStat extends Model
{
    use BelongsToWorkspace;

    protected $table = 'domain_daily_stats';

    protected $fillable = ['domain_id', 'day', 'sent', 'delivered', 'bounced', 'complained', 'opened', 'clicked'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
