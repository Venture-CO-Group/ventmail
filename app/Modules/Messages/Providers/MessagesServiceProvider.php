<?php

declare(strict_types=1);

namespace App\Modules\Messages\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Messages modul — a publikus Messages API (Mailgun-paritás).
 *
 * A route-ok API-kulccsal hitelesítettek (nem session-nel), ezért a modul saját stacket
 * kap: `api` + `throttle:api` + `api.auth`. A `web`/`auth`/`workspace` stack itt nem
 * értelmezett — a workspace-kontextus magából a kulcsból származik.
 */
final class MessagesServiceProvider extends ModuleServiceProvider
{
    /** A `/v1` API-kulccsal hitelesít, nem böngésző-session-nel. */
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';
}
