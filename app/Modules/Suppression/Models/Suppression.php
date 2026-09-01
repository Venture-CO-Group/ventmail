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

    /**
     * Normalizálás MUTATORBAN, nem `creating` hookban: különben a `firstOrCreate` a nyers
     * értékkel keresne (nem talál), normalizálva írna, és a második hívás unique-violationnel
     * elszállna — épp a bounce-kezelés közben, így a hard bounce nem kerülne suppressionre.
     *
     * A hash HMAC pepperrel készül: egy sózatlan e-mail-hash szótárral triviálisan
     * visszafejthető lenne.
     */
    public function setEmailAttribute(?string $value): void
    {
        $normalized = strtolower(trim((string) $value));

        $this->attributes['email'] = $normalized;
        $this->attributes['email_hash'] = hash_hmac('sha256', $normalized, (string) config('audit.hash_pepper'));
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
