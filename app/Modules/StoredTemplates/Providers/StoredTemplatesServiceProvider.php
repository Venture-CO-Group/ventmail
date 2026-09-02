<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * StoredTemplates modul — tárolt sablonok a Platform-oldalon (spec 15.).
 * (A Studio-oldali block-editor sablonjai a `Template` modulban élnek — B2.)
 */
final class StoredTemplatesServiceProvider extends ModuleServiceProvider
{
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';
}
