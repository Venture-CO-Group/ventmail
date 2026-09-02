<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\AuthorizeSsoSubject;
use App\Modules\Identity\Actions\SyncSsoUser;
use App\Modules\Identity\Services\OidcClient;
use App\Modules\Identity\Services\RoleMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A ventSSO visszairányításának feldolgozása.
 *
 * A bejelentkezés `Auth::login()`-nal történik (session-migrációval), sosem kézi
 * session-írással — utóbbi session-fixációt engedne. `$remember` SOHA nem igaz:
 * a ventMail nem tart remember-tokent.
 */
final class OidcCallbackController
{
    public function __invoke(
        Request $request,
        OidcClient $client,
        SyncSsoUser $syncUser,
        RoleMapper $roleMapper,
        AuthorizeSsoSubject $authorizeSubject,
    ): RedirectResponse {
        $claims = $client->handleCallback($request);

        $roles = $roleMapper->map($claims);

        // Sor létrehozása ELŐTT: jogosultság nélküli alanyról PII-t sem tárolunk.
        $authorizeSubject($claims, $roles);

        $user = $syncUser($claims);

        Auth::login($user);
        $request->session()->regenerate();

        // DIAGNOSZTIKA. Jogosultsági döntéshez TILOS használni: a hozzáférés egyetlen
        // forrása a `workspace_members` tábla (golden rule 1).
        $request->session()->put('oidc.roles_diagnostic', $roles);

        return redirect()->intended('/');
    }
}
