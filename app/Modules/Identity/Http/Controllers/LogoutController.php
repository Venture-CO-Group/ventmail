<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\OidcDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Kijelentkezés.
 *
 * A lokális munkamenetet mindig érvényteleníti; ha a ventSSO hirdet `end_session_endpoint`-ot,
 * oda irányít tovább (RP-initiated logout), hogy az IdP-oldali munkamenet is záruljon.
 */
final class LogoutController
{
    public function __invoke(Request $request, OidcDiscovery $discovery): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        try {
            $endSession = $discovery->document()['end_session_endpoint'] ?? null;
        } catch (\Throwable) {
            $endSession = null; // az IdP elérhetetlensége nem akadályozhatja a kilépést
        }

        if (is_string($endSession) && $endSession !== '') {
            return redirect()->away($endSession.'?'.http_build_query([
                'client_id' => config('oidc.client_id'),
                'post_logout_redirect_uri' => url('/'),
            ]));
        }

        return redirect('/');
    }
}
