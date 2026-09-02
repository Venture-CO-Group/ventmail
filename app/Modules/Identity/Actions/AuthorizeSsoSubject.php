<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Van-e az alanynak keresnivalója a ventMailben?
 *
 * A ventSSO a teljes Venture-családé: az `aud` claim csak azt igazolja, hogy a tokent
 * NEKÜNK állították ki, nem azt, hogy a felhasználónak van itt hozzáférése. Enélkül a
 * realm minden felhasználója belépne, és PII-sort (név, e-mail) hoznánk létre olyanokról,
 * akiknek semmi közük az apphoz — GDPR-szempontból jogalap nélküli adatkezelés.
 *
 * Belépési jogcím: (a) leképzett `ventmail` szerep a tokenben, VAGY (b) meglévő tagság.
 */
final class AuthorizeSsoSubject
{
    /** @param list<string> $roles */
    public function __invoke(array $claims, array $roles): void
    {
        if ($roles !== []) {
            return;
        }

        $existing = User::query()
            ->where('sso_issuer', (string) ($claims['iss'] ?? ''))
            ->where('sso_subject', (string) ($claims['sub'] ?? ''))
            ->first();

        $hasMembership = $existing !== null
            && WorkspaceMember::query()->where('user_id', $existing->getKey())->exists();

        if (! $hasMembership) {
            throw new AuthorizationException(
                'Ehhez a ventSSO-alanyhoz nincs ventMail-hozzáférés. Kérj meghívót a workspace tulajdonosától.'
            );
        }
    }
}
