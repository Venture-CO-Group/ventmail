<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * API-kulcs (spec 24.). A nyers kulcs SOHA nem kerül tárolásra — csak hash, plusz egy
 * megjelenítési prefix. A kulcs egyetlen egyszer, a létrehozáskor látszik.
 */
final class ApiKey extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'prefix', 'key_hash', 'scopes', 'testmode', 'expires_at', 'last_used_at'];

    protected $hidden = ['key_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'testmode' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Új kulcs kiállítása. A visszaadott nyers kulcs a HÍVÓ felelőssége — másodszor nem
     * lesz elérhető.
     *
     * @param  list<string>  $scopes
     * @return array{model: self, plain: string}
     */
    public static function issue(string $name, array $scopes = [], bool $testmode = false): array
    {
        $plain = ($testmode ? 'vm_test_' : 'vm_live_').Str::random(48);

        $model = self::create([
            'name' => $name,
            'prefix' => substr($plain, 0, 16),
            'key_hash' => self::hashOf($plain),
            'scopes' => $scopes,
            'testmode' => $testmode,
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
        $key = self::query()
            ->where('key_hash', self::hashOf($plain))
            ->first();

        return $key !== null && $key->matches($plain) ? $key : null;
    }

    public function matches(string $plain): bool
    {
        return hash_equals((string) $this->key_hash, self::hashOf($plain));
    }

    private static function hashOf(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('audit.hash_pepper'));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }
}
