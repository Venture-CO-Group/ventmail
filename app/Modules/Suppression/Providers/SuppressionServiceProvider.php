<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Suppression modul — Globális és workspace-szintű suppression lista. GDPR-törlés után is MEGMARAD.
 */
final class SuppressionServiceProvider extends ModuleServiceProvider
{
    // Az ONE-CLICK LEIRATKOZÁST a LEVÉLKLIENS hívja (Gmail, Yahoo, Apple Mail), nem a
    // bejelentkezett böngésző: a hozzáférést a titkosított token adja. A route-onkénti
    // `throttle` és a stateless stack a `routes/web.php`-ben van, explicit.
    protected bool $routesPrecedeAuthentication = true;

    /** v1.1 · 7. modul: `/v1/suppressions` — API-kulccsal, scope-kapuval. */
    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';
}
