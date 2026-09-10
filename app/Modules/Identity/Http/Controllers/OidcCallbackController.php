<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\AuthorizeSsoSubject;
use App\Modules\Identity\Actions\SyncSsoUser;
use App\Modules\Identity\Services\OidcClient;
use App\Modules\Identity\Services\PlatformAdminSync;
use App\Modules\Identity\Services\RoleMapper;
use App\Modules\Tenancy\Actions\RedeemInvitations;
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
        PlatformAdminSync $platformAdmin,
        AuthorizeSsoSubject $authorizeSubject,
        RedeemInvitations $redeemInvitations,
    ): RedirectResponse {
        $claims = $client->handleCallback($request);

        $roles = $roleMapper->map($claims);

        // Sor létrehozása ELŐTT: jogosultság nélküli alanyról PII-t sem tárolunk.
        $authorizeSubject($claims, $roles);

        $user = $syncUser($claims);

        /*
         | A PLATFORM-ADMIN JOGOSULTSÁG ÚJRASZÁMOLÁSA minden bejelentkezésnél (C2 · 81.).
         |
         | A `users.platform_admin` oszlop CACHE, nem forrás. Enélkül egy ventSSO-oldali
         | visszavonás nálunk nem érvényesülne: a leszerelt kollégának másnap is lenne
         | hozzáférése MINDEN tenant adatához.
         */
        $platformAdmin->sync($user, $claims);

        Auth::login($user);
        $request->session()->regenerate();

        /*
         | NYITOTT MEGHÍVÓ ESETÉN a MEGHÍVOTT DÖNT — nem váltjuk be magától.
         |
         | Az automatikus beváltás azt jelentette volna, hogy egy idegen szervezet PII-t
         | szerez valakiről (név, cím, utolsó belépés a tagok-képernyőjükön) pusztán attól,
         | hogy beírta a címét egy űrlapba. A `/invitations` képernyőn az érintett elfogad
         | vagy elutasít; az elutasítás törli a sort.
         |
         | A `users` sor addigra létezik (a ventSSO belépése hozta létre) — identitást
         | továbbra sem gyártunk (golden rule 1).
         */
        if ($redeemInvitations->openFor($user)->isNotEmpty()) {
            return redirect('/invitations');
        }

        // DIAGNOSZTIKA. Jogosultsági döntéshez TILOS használni: a hozzáférés egyetlen
        // forrása a `workspace_members` tábla (golden rule 1).
        $request->session()->put('oidc.roles_diagnostic', $roles);

        return redirect()->intended('/');
    }
}
