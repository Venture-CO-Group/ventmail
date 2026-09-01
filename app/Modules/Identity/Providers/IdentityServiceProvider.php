<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Identity modul — ventSSO OIDC kliens: login-callback, token-verifikáció, szerep-leképezés.
 * Jelszót SOHA nem kezel (golden rule 1).
 */
final class IdentityServiceProvider extends ModuleServiceProvider
{
    /**
     * A `/auth/*` útvonalak természetüknél fogva hitelesítés ELŐTTIEK: itt még nincs user
     * és nincs workspace-kontextus. Ez az EGYETLEN modul, amely ezt a kivételt kapja.
     */
    protected bool $routesPrecedeAuthentication = true;

    /** Hitelesítetlen végpont: IP-alapú limit a session- és IdP-terhelés ellen. */
    protected array $webMiddleware = ['throttle:oidc'];
}
