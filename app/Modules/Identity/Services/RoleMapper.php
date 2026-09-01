<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\Role;
use Illuminate\Support\Arr;

/**
 * A ventSSO `resource_access.ventmail.roles` claimjének leképezése a hat ventMail-szerepre.
 *
 * FEHÉRLISTÁS: ismeretlen szerep nem ad jogot, csendben kimarad. A leképezés SOHA nem
 * ad workspace-tagságot — azt kizárólag a `workspace_members` tábla adja (golden rule 1).
 */
final class RoleMapper
{
    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    public function map(array $claims): array
    {
        $raw = Arr::get($claims, (string) config('oidc.roles_claim'), []);

        return collect((array) $raw)
            ->map(fn ($value) => is_string($value) ? Role::tryFromClaim($value) : null)
            ->filter()
            ->map(fn (Role $role) => $role->value)
            ->unique()
            ->values()
            ->all();
    }
}
