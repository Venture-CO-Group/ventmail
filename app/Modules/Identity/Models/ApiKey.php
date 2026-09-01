<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * API-kulcs (spec 24.). A nyers kulcs SOHA nem kerül tárolásra — csak hash, plusz egy
 * megjelenítési prefix. A kulcs egyetlen egyszer, a létrehozáskor látszik.
 */
final class ApiKey extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'prefix', 'key_hash', 'scopes', 'expires_at', 'last_used_at'];

    protected $hidden = ['key_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
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
    public static function issue(string $name, array $scopes = []): array
    {
        $plain = 'vm_'.Str::random(48);

        $model = self::create([
            'name' => $name,
            'prefix' => substr($plain, 0, 12),
            'key_hash' => Hash::make($plain),
            'scopes' => $scopes,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    public function matches(string $plain): bool
    {
        return Hash::check($plain, (string) $this->key_hash);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }
}
