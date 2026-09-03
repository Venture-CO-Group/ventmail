<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Scopes\WorkspaceScope;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SMTP-hitelesítő — GÉPI hitelesítő, nem felhasználói fiók.
 *
 * A felhasználónevet a RENDSZER generálja (`vm-<workspace>-<random>`): ha a tenant
 * választhatná, „A" lefoglalhatná „B" várható nevét, és a globálisan egyedi oszlop miatt
 * „B" soha nem tudná kiállítani a sajátját.
 *
 * A `require_tls` a KIMENŐ lábra vonatkozik (`o:require-tls`) — a submissionre MINDIG kell
 * TLS (golden rule 3). Ezt ki kell mondani, különben a következő fejlesztő azt hiheti,
 * hogy `require_tls = false`-szal plaintext submission engedélyezhető.
 */
final class SmtpCredential extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = [
        'domain_id', 'name', 'username', 'password_hash', 'require_tls',
        'channel_restriction', 'rate_limit_per_minute', 'expires_at', 'revoked_at', 'rotated_from_id',
    ];

    protected $hidden = ['password_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'require_tls' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Új hitelesítő kiállítása. A nyers jelszó EGYSZER látszik, utána soha.
     *
     * @return array{credential: self, username: string, password: string}
     */
    public static function issue(string $name, ?int $domainId = null, string $channel = 'transactional'): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        $username = 'vm-'.substr(hash('sha256', (string) $workspaceId), 0, 8).'-'.Str::lower(Str::random(10));
        $password = Str::random(32);

        $credential = self::create([
            'domain_id' => $domainId,
            'name' => $name,
            'username' => $username,
            // A jelszó JELSZÓ: lassú hash, nem a KeyedHash (az API-kulcsokra való, ahol a
            // bemenet 48 karakternyi entrópia és a lookupnak indexelhetőnek kell lennie).
            //
            // A Laravel `Hash` facade-ját használjuk, NEM a `password_hash` alapértelmezését:
            // a `PASSWORD_DEFAULT` költsége PHP-verziónként más (8.3: 10, 8.4+: 12), és ha a
            // valódi hash olcsóbb, mint a hitelesítés dummy összehasonlítása, a nem létező
            // felhasználó LASSABB választ ad — abból pedig kiderül, melyik név létezik.
            'password_hash' => Hash::make($password),
            'channel_restriction' => $channel,
        ]);

        return ['credential' => $credential, 'username' => $username, 'password' => $password];
    }

    /**
     * Feloldás felhasználónév alapján — tenant-kontextus NÉLKÜL.
     *
     * AUTH-időben még nincs workspace: a kontextus MAGÁBÓL a hitelesítőből származik
     * (ugyanaz a precedens, mint az `ApiKey::findByPlainKey`). Ezért ez az EGYETLEN hely,
     * ahol a scope kikapcsolódik, és pontos egyezésre korlátozva.
     */
    public static function findByUsername(string $username): ?self
    {
        return self::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('username', $username)
            ->first();
    }

    /** Feloldás uuid alapján — a transzportnak, hitelesítés UTÁN (nyom-írás). */
    public static function findByUuid(string $uuid): ?self
    {
        return self::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('uuid', $uuid)
            ->first();
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function touchUsage(string $ip): void
    {
        // NYERS IP nem tárolható: kulcsolt hash, saját domain-elválasztással.
        $this->forceFill([
            'last_used_at' => now(),
            'last_ip_hash' => KeyedHash::of('smtp_source_ip', $ip),
        ])->saveQuietly();
    }
}
