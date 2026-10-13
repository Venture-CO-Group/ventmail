<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Models\User;
use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy IMPORT-KÖTEG nyilvántartása (v1.1): ki, mikor, melyik fájl, milyen jogalappal és
 * forrással, milyen eredménnyel. A kontakt-szintű nyomot a `consent_events` `imported` sora
 * viszi; ez a köteg-szintű.
 *
 * ## Mi NEM kerül az audit-naplóba
 *
 * A `profile` és a `stats` mintasorokat (valódi címeket) tartalmaz, a `storage_path` pedig
 * belső útvonal — az audit-tábla sosem törölhető, ezért ezek `[redacted]`-ként szerepelnek.
 * A jogalap, a forrás, a fájlnév és a darabszámok viszont bent vannak: pont ezekre kell
 * hivatkozni egy hatósági kérdésnél.
 */
final class ContactImport extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    public const STATUS_DRAFT = 'draft';

    /** A feltöltés után a háttérjob még felmér (fejléc, minta, leképezés-javaslat). */
    public const STATUS_INSPECTING = 'inspecting';

    public const STATUS_PREVIEWING = 'previewing';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'source_type', 'file_name', 'file_hash', 'file_size', 'storage_path',
        'profile', 'mapping', 'contact_list_id', 'tags', 'update_mode', 'legal_basis',
        'consent_source', 'consent_date', 'resulting_status', 'role_policy', 'disposable_policy',
        'status', 'phase', 'total_rows', 'processed_rows', 'new_count', 'updated_count',
        'skipped_count', 'error_count', 'stats', 'error_report_path', 'failure_reason',
        'started_at', 'completed_at',
    ];

    public array $auditRedacted = ['profile', 'stats', 'storage_path', 'error_report_path', 'file_name'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'mapping' => 'array',
            'tags' => 'array',
            'stats' => 'array',
            'consent_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'contact_list_id');
    }

    /** Az audit-payloadba a fájlnév HELYETT: a kiterjesztés (kézi/beillesztett bevitelnél a forrás-típus). */
    public function fileExtension(): string
    {
        $extension = strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : (string) $this->source_type;
    }

    /** A futás véget ért (sikerrel vagy hibával). */
    public function finished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
