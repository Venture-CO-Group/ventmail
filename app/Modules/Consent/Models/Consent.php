<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Marketing-consent (spec 17.) — a PreSendGate 3. kapujának adatforrása.
 *
 * A double opt-in FOLYAMAT (megerősítő levél, preference center, űrlapok) a B1 packet
 * gazdája; itt a bizonyíték-rekord él, hogy a gate ne stubbal induljon.
 */
final class Consent extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'email', 'channel', 'status', 'legal_basis', 'source', 'policy_version',
        'confirmed_at', 'revoked_at', 'ledger_receipt_id', 'ledger_receipt_at',
    ];

    /** Az audit-tábla sosem törölhető — érintetti e-mail-cím nem kerülhet bele. */
    public array $auditRedacted = ['email'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'revoked_at' => 'datetime', 'ledger_receipt_at' => 'datetime'];
    }

    public function setEmailAttribute(?string $value): void
    {
        $normalized = DomainNormalizer::listKey(trim((string) $value));

        $this->attributes['email'] = $normalized;
        $this->attributes['email_hash'] = KeyedHash::of('email', $normalized);
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed' && $this->revoked_at === null;
    }

    /**
     * A HASZNÁLHATÓ consent egyetlen definíciója — a gate is ezt hívja.
     *
     * Enélkül két igazság-definíció élne egymás mellett (a modellé és a gate lekérdezéséé),
     * és a gyengébb nyerne: a visszavont consenttel kiment volna a marketing.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'confirmed')
            ->whereNotNull('confirmed_at')
            ->whereNull('revoked_at');
    }
}
