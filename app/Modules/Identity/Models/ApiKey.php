<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * API-kulcs (spec 24.). A nyers kulcs SOHA nem kerül tárolásra — csak hash, plusz egy
 * megjelenítési prefix. A kulcs egyetlen egyszer, a létrehozáskor látszik.
 */
final class ApiKey extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'prefix', 'key_hash', 'scopes', 'channels', 'testmode', 'expires_at', 'last_used_at', 'ip_allowlist'];

    /**
     * Régi scope-nevek → a mai `/v1` kapu neve (D14).
     *
     * A felület korábban `validate`-et adott ki, a route viszont `validate:run`-t vár: az így
     * kiállított kulcsok sosem érték el a címellenőrzést. A régi kulcsot NEM írjuk át (a hash
     * mellett a scope-lista is az ügyfél szerződése), hanem a régi nevet a kapu alias-ként
     * elfogadja.
     */
    private const SCOPE_ALIASES = ['validate:run' => ['validate']];

    protected $hidden = ['key_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'channels' => 'array',
            'testmode' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'ip_allowlist' => 'array',
        ];
    }

    /**
     * Új kulcs kiállítása. A visszaadott nyers kulcs a HÍVÓ felelőssége — másodszor nem
     * lesz elérhető.
     *
     * @param  list<string>  $scopes
     * @return array{model: self, plain: string}
     */
    public static function issue(string $name, array $scopes = [], bool $testmode = false, ?array $channels = null, ?array $ipAllowlist = null): array
    {
        $plain = ($testmode ? 'vm_test_' : 'vm_live_').Str::random(48);

        $model = self::create([
            'name' => $name,
            'prefix' => substr($plain, 0, 16),
            'key_hash' => self::hashOf($plain),
            'scopes' => $scopes,
            'channels' => $channels,
            'testmode' => $testmode,
            'ip_allowlist' => $ipAllowlist === [] ? null : $ipAllowlist,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    /**
     * A kulcs feloldása EGYETLEN indexelt lekérdezéssel.
     *
     * Miért SHA-256 és nem bcrypt: az API-kulcs nagy entrópiájú, gépi generálású titok,
     * nem jelszó — nincs mit brute-force-olni. A bcrypt viszont (a) lassú (mérve ~222 ms
     * hívásonként), és (b) a random só miatt a hash NEM kereshető, tehát minden kérésnél
     * végig kellene próbálni a workspace összes kulcsát.
     */
    public static function findByPlainKey(string $plain): ?self
    {
        // Az API-auth valós helyzetében a workspace-kontextus MAGÁBÓL a kulcsból származik,
        // tehát lookup-időben még nincs. Ezért a global scope-ot itt — és KIZÁRÓLAG itt,
        // a hash pontos egyezésére — kikapcsoljuk; a hívó ezután azonnal beállítja a
        // kontextust a kulcs workspace-ére. Enélkül minden API-hívás fail-closed 401 lenne,
        // és a következő fejlesztő a scope általános kikapcsolásáért nyúlna.
        $key = self::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('key_hash', self::hashOf($plain))
            // Lejárt kulcs SOHA nem érvényes: egy auth-primitívnél a fail-open alap rossz.
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        return $key !== null && $key->matches($plain) ? $key : null;
    }

    public function matches(string $plain): bool
    {
        // A rotációs átfedést is figyelembe veszi: a pepper cseréje különben egyszerre
        // lőné ki minden ügyfél minden kulcsát.
        return KeyedHash::matches('api_key', $plain, (string) $this->key_hash);
    }

    private static function hashOf(string $plain): string
    {
        return KeyedHash::of('api_key', $plain);
    }

    public function hasScope(string $scope): bool
    {
        $granted = (array) $this->scopes;

        if (in_array($scope, $granted, true)) {
            return true;
        }

        foreach (self::SCOPE_ALIASES[$scope] ?? [] as $legacy) {
            if (in_array($legacy, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hívhat-e a kulccsal ez a forrás-cím (IP-allowlist, D14)?
     *
     * Üres / hiányzó lista = bármely cím (a meglévő kulcsok viselkedése nem változik). A
     * címet a hívó a `ClientAddress`-ből adja — a valódi TCP-partnert vagy a megbízható
     * edge által továbbítottat, nem a kérő által beírt `X-Forwarded-For`-t.
     */
    public function allowsAddress(string $ip): bool
    {
        $allowlist = array_values(array_filter(array_map(strval(...), (array) $this->ip_allowlist)));

        if ($allowlist === []) {
            return true;
        }

        // IPv4-mapped IPv6 (`::ffff:1.2.3.4`) → a beágyazott IPv4 (N3): dual-stack figyelőn a
        // kliens így is látszhat, és egy IPv4-es allowlist különben sosem illeszkedne.
        $binary = @inet_pton($ip);

        if ($binary !== false && strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $ip = (string) inet_ntop(substr($binary, 12));
        }

        return IpUtils::checkIp($ip, $allowlist);
    }

    /**
     * A kulcsnak ADOTT csatornák.
     *
     * `null` = konzervatív alapértelmezés: csak tranzakciós. A marketing küldés így
     * SZÁNDÉKOS kiállítói döntés, nem a hívó futásidejű választása.
     *
     * @return list<string>
     */
    public function allowedChannels(): array
    {
        $channels = $this->channels;

        return is_array($channels) && $channels !== []
            ? array_values(array_map(strval(...), $channels))
            : ['transactional'];
    }

    public function mayUseChannel(string $channel): bool
    {
        return in_array($channel, $this->allowedChannels(), true);
    }
}
