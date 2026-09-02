<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Services\KeyedHash;
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

    // Az email_hash SZÁNDÉKOSAN nincs a fillable-ben: a mutátor számolja. Kívülről
    // beadva egy hamis hash tartósan beíródna (és az auditba is bekerülne).
    protected $fillable = ['email', 'reason', 'applies_to', 'source', 'detail', 'expires_at'];

    /** Az audit-tábla sosem törölhető — érintetti e-mail-cím nem kerülhet bele. */
    public array $auditRedacted = ['email'];

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
        $this->attributes['email_hash'] = KeyedHash::of('email', $normalized);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
