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
        $user->locale = (string) ($claims['locale'] ?? config('app.locale'));
        $user->email_verified_at = $verified ? ($user->email_verified_at ?? now()) : null;
        $user->last_login_at = now();

        $user->save();

        return $user;
    }
}
