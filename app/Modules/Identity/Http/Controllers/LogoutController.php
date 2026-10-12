<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\OidcDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Kijelentkezés.
 *
 * A lokális munkamenetet mindig érvényteleníti; ha a ventSSO hirdet `end_session_endpoint`-ot,
 * oda irányít tovább (RP-initiated logout), hogy az IdP-oldali munkamenet is záruljon.
 */
final class LogoutController
{
    public function __invoke(Request $request, OidcDiscovery $discovery): RedirectResponse|SymfonyResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        try {
            $endSession = $discovery->document()['end_session_endpoint'] ?? null;
        } catch (\Throwable) {
            $endSession = null; // az IdP elérhetetlensége nem akadályozhatja a kilépést
        }

        $target = is_string($endSession) && $endSession !== ''
            ? $endSession.'?'.http_build_query([
                'client_id' => config('oidc.client_id'),
                'post_logout_redirect_uri' => url('/'),
            ])
            : url('/');

        // Az app-héj Inertia XHR-rel hívja: külső átirányítást az nem követ, ezért ott
        // 409 + X-Inertia-Location (teljes oldalas navigáció a ventSSO-ra).
        return $request->header('X-Inertia')
            ? Inertia::location($target)
            : redirect()->away($target);
    }
}
