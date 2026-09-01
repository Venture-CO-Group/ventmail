<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;

/**
 * A ventSSO-alany tükrözése a lokális `users` táblába.
 *
 * A párosítás KIZÁRÓLAG a `sub` claim alapján történik. E-mail alapú párosítás
 * pre-registration account takeover-t engedne: bárki, aki egy létező e-mail címmel
 * regisztrál a ventSSO-ban, átvenné a lokális sort. Az e-mail csak megjelenítési adat.
 */
final class SyncSsoUser
{
    /** @param array<string, mixed> $claims */
    public function __invoke(array $claims): User
    {
        $subject = (string) ($claims['sub'] ?? '');

        if ($subject === '') {
            throw new \InvalidArgumentException('Az ID-tokenben nincs `sub` claim.');
        }

        $user = User::firstOrNew(['sso_subject' => $subject]);

        $user->name = (string) ($claims['name'] ?? $claims['preferred_username'] ?? $subject);
        $user->email = (string) ($claims['email'] ?? $subject.'@sso.local');
        $user->locale = (string) ($claims['locale'] ?? config('app.locale'));
        $user->last_login_at = now();

        if (($claims['email_verified'] ?? false) === true && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }
}
