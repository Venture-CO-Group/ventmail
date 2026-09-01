<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Audit-sor. MEGVÁLTOZTATHATATLAN: aki egyszer beírt, azt nem lehet átírni vagy törölni —
 * különben a napló pont akkor lenne hamisítható, amikor a legfontosabb (golden rule 11).
 *
 * Szándékosan NEM használ `BelongsToWorkspace`-t: a naplónak akkor is íródnia kell, amikor
 * nincs workspace-kontextus (pl. rendszerszintű művelet), és a super-admin (C2)
 * tenant-független kereséssel dolgozik. Az olvasást a `forWorkspace()` szűkíti.
 */
final class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'user_id', 'action', 'auditable_type', 'auditable_id',
        'before', 'after', 'context', 'ip_hash', 'user_agent',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Az audit-napló nem módosítható.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Az audit-napló nem törölhető.');
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
