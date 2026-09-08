<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy forrás-IP egy DMARC-jelentésben.
 *
 * A `known_source` az a mező, amiért az egész tábla létezik: az ISMERETLEN forrás vagy egy
 * elfelejtett saját rendszer (a marketinges regisztrált egy külső eszközt), vagy egy
 * hamisító. Mindkettő azonnali figyelmet érdemel, és a jelentés nélkül egyikről sem tudnánk.
 */
final class DmarcReportRow extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'dmarc_report_id', 'source_ip', 'count', 'disposition',
        'spf_result', 'dkim_result', 'header_from', 'known_source',
    ];

    protected function casts(): array
    {
        return ['known_source' => 'boolean', 'count' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DmarcReport::class, 'dmarc_report_id');
    }

    /** Átment-e a DMARC-on? SPF **VAGY** DKIM — az igazodáshoz az egyik elég (RFC 7489 §6.6.2). */
    public function passes(): bool
    {
        return strtolower((string) $this->spf_result) === 'pass'
            || strtolower((string) $this->dkim_result) === 'pass';
    }
}
