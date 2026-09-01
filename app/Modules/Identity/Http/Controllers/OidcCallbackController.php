<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

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
    ): RedirectResponse {
        $claims = $client->handleCallback($request);

        $user = $syncUser($claims);

        Auth::login($user);
        $request->session()->regenerate();

        // A szerepek a tokenből jönnek; a TENANT-TAGSÁG NEM — azt a workspace_members adja.
        $request->session()->put('oidc.roles', $roleMapper->map($claims));

        return redirect()->intended('/');
    }
}
