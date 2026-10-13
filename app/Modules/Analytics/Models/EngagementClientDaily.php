<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * ESZKÖZ- ÉS KLIENS-BONTÁS napi eseményszáma hatókörönként (v1.1 · 8. modul, D229).
 *
 * Gépi adat (a nyilvános tracking-végpont írja), csak OSZTÁLYOZOTT kategóriát tart — a nyers
 * User-Agent sehol nem perzisztálódik.
 */
final class EngagementClientDaily extends Model
{
    use BelongsToWorkspace;

    protected $table = 'engagement_client_daily';

    protected $fillable = ['scope', 'day', 'kind', 'device', 'client', 'total'];

    protected function casts(): array
    {
        return ['day' => 'date', 'total' => 'integer'];
    }
}
