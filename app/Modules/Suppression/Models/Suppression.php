<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspaceOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Suppression-bejegyzés — a PreSendGate 2. kapuja (spec 6.2).
 *
 * `workspace_id = NULL` = GLOBÁLIS tiltás (minden tenantra érvényes).
 * GDPR-törlés után is megmarad: a leiratkozás tényét nem szabad elfelejteni.
 */
final class Suppression extends Model
{
    use Auditable, BelongsToWorkspaceOrPlatform, HasUuids;

    protected $fillable = ['email', 'email_hash', 'reason', 'source', 'detail', 'expires_at'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $suppression): void {
            $suppression->email = strtolower(trim((string) $suppression->email));
            $suppression->email_hash = hash('sha256', $suppression->email);
        });
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
