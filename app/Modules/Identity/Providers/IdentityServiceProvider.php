<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Identity modul — ventSSO OIDC kliens: login-callback, token-verifikáció, szerep-leképezés.
 * Jelszót SOHA nem kezel (golden rule 1).
 */
final class IdentityServiceProvider extends ModuleServiceProvider {}
