<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A CONSENT TÖRTÉNETE — append-only bizonyíték (57. kritérium).
 *
 * A `consents` sor a MATERIALIZÁLT AKTUÁLIS állapot: azt kérdezi meg a PreSendGate 3. kapuja,
 * egyetlen lekérdezéssel, minden küldésnél. EZ a tábla viszont a történet — és egy jogi
 * vitában nem az a kérdés, hogy MOST van-e hozzájárulás, hanem hogy VOLT-E akkor, amikor
 * küldtünk.
 *
 * A megváltoztathatatlanságot ADATBÁZIS-TRIGGER őrzi, nem ez a modell: a query builder
 * közvetlen használata a modell-hookot megkerüli, egy bizonyíték-tábla pedig pont attól
 * bizonyíték, hogy utólag nem írható át. A modell-szintű őr itt csak azért van, hogy a hiba
 * a fejlesztőnél, érthető üzenettel jelentkezzen, ne egy nyers Postgres-kivételként.
 *
 * (A megfogalmazás szándékosan kerüli a metódusnevet: a `ScopeBypassGuardTest` a
 * forrásszövegben keres, és egy magyarázó komment miatt nem szabad kivételt adni neki — a
 * kivétel ettől a fájltól VÉGLEG elvenné az őrt.)
 */
final class ConsentEvent extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'consent_id', 'email', 'type', 'channel', 'legal_basis', 'source',
        'ip_hash', 'user_agent_hash', 'policy_version', 'form_text', 'payload', 'occurred_at',
    ];

    protected $attributes = ['payload' => '{}', 'channel' => 'marketing', 'legal_basis' => 'consent'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->occurred_at ??= now();
        });

        foreach (['updating', 'deleting'] as $forbidden) {
            self::{$forbidden}(function () use ($forbidden): void {
                throw new RuntimeException(sprintf(
                    'A consent-bizonyíték append-only: a %s művelet tilos (57. kritérium). '
                    .'Állapotváltáshoz ÚJ eseményt kell írni.',
                    $forbidden
                ));
            });
        }
    }

    public function setEmailAttribute(?string $value): void
    {
        $normalized = DomainNormalizer::listKey(trim((string) $value));

        $this->attributes['email'] = $normalized === '' ? null : $normalized;
        $this->attributes['email_hash'] = KeyedHash::of('email', $normalized);
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }
}
