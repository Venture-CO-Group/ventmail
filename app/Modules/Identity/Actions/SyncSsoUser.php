<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use InvalidArgumentException;

/**
 * A ventSSO-alany tükrözése a lokális `users` táblába.
 *
 * A párosítás kulcsa a (`iss`, `sub`) pár:
 * - a `sub` csak kibocsátón belül egyedi, ezért a provider is a kulcs része;
 * - e-mail alapú párosítás pre-registration account takeover-t engedne (bárki, aki egy
 *   létező címmel regisztrál a ventSSO-ban, átvenné a lokális sort). Az e-mail csak
 *   megjelenítési adat.
 */
final class SyncSsoUser
{
    /** @param array<string, mixed> $claims */
    public function __invoke(array $claims): User
    {
        $issuer = (string) ($claims['iss'] ?? '');
        $subject = (string) ($claims['sub'] ?? '');

        if ($issuer === '' || $subject === '') {
            throw new InvalidArgumentException('Az ID-tokenben nincs `iss` vagy `sub` claim.');
        }

        // Atomikus: két párhuzamos első belépés nem eshet unique-violationbe.
        $user = User::firstOrCreate(
            ['sso_issuer' => $issuer, 'sso_subject' => $subject],
            ['name' => $subject, 'email' => $subject.'@sso.local']
        );

        $email = (string) ($claims['email'] ?? $user->email);
        $verified = ($claims['email_verified'] ?? false) === true;

        // Cím-váltáskor a korábbi verifikáltság NEM öröklődik: egy nem verifikált új cím
        // különben verifikáltnak látszana, és később feladót vagy meghívót kapuzhatna.
        if ($user->email !== $email) {
            $user->email_verified_at = null;
        }

        $user->name = (string) ($claims['name'] ?? $claims['preferred_username'] ?? $subject);
        $user->email = $email;
        $this->applyLocale($user, $claims['locale'] ?? null);
        $user->email_verified_at = $verified ? ($user->email_verified_at ?? now()) : null;
        $user->last_login_at = now();

        $user->save();

        return $user;
    }

    /**
     * A NYELV FORRÁSA (D11): amíg a felhasználó nem választott (`locale_source` null vagy
     * `sso`), a ventSSO `locale` claimje dönt; a saját választás (`user`) erősebb, és a
     * bejelentkezés többé nem írja felül.
     *
     * Támogatott claim nélkül nem jelöljük `sso`-nak a nyelvet: az alapnyelv nem „a
     * ventSSO döntése", és a `SetLocale` ilyenkor az `Accept-Language`-re eshet vissza.
     */
    private function applyLocale(User $user, mixed $claim): void
    {
        if ($user->raw('locale_source') === 'user') {
            return;
        }

        $supported = (array) config('app.supported_locales', []);
        $locale = $this->supportedLocale($claim);

        $user->locale = $locale;
        $user->locale_source = $this->isSupportedClaim($claim, $supported) ? 'sso' : null;
    }

    /** @param array<int, mixed> $supported */
    private function isSupportedClaim(mixed $claim, array $supported): bool
    {
        if (! is_string($claim)) {
            return false;
        }

        $base = explode('-', str_replace('_', '-', strtolower(trim($claim))))[0];

        return in_array($base, $supported, true);
    }

    /**
     * A NYELV a TÁMOGATOTT listából, vagy az alap.
     *
     * A claim IDEGEN BEMENET: az IdP bármit adhat (`de-CH`, `zz`, vagy egy path-szerű
     * sztringet), és eddig ellenőrzés nélkül a `users.locale`-be került. A Laravel a
     * furcsa értékre később `InvalidArgumentException`-t dob, a felhasználó pedig egy
     * nem létező nyelvet „választ" — ami a felületen üres feliratokat jelentene.
     *
     * A régió-változat elfogadható, ha az alapnyelv támogatott: `hu-HU` → `hu`.
     */
    private function supportedLocale(mixed $claim): string
    {
        $supported = (array) config('app.supported_locales', []);
        $candidate = is_string($claim) ? strtolower(trim($claim)) : '';
        $base = str_replace('_', '-', $candidate);
        $base = explode('-', $base)[0];

        return in_array($base, $supported, true) ? $base : (string) config('app.locale');
    }
}
