<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BRANDELT PDF-RIPORT egy kérése és életciklusa (v1.1 · 8. modul).
 *
 * `queued → rendering → ready → expired`, hibánál `failed` (`failure_code`). A fájl a privát
 * `reports` diszken él (`ReportArtifactStore`), 7 napig; a sor a lejárat után `expired`-ként
 * MARAD (auditnyom: ki, mikor, milyen paraméterrel kért riportot).
 *
 * @property string $uuid
 * @property string $status
 */
final class AnalyticsReport extends Model
{
    use BelongsToWorkspace, HasUuids;

    public const STATUSES = ['queued', 'rendering', 'ready', 'failed', 'expired'];

    public const SECTIONS = ['kpis', 'charts', 'top_links', 'journeys', 'deliverability'];

    protected $fillable = [
        'requested_by', 'kind', 'campaign_uuid', 'period_from', 'period_to', 'timezone', 'locale',
        'sections', 'params_hash', 'status', 'failure_code', 'file_path', 'file_size', 'page_count',
        'sha256', 'started_at', 'finished_at', 'expires_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'period_from' => 'date',
            'period_to' => 'date',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
