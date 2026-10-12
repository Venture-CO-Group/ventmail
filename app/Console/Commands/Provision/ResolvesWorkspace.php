<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use RuntimeException;

/** A provisioning-parancsok közös része: a workspace slug alapján, kontextusba állítva. */
trait ResolvesWorkspace
{
    private function enterWorkspace(string $slug): Workspace
    {
        $workspace = Workspace::query()->where('slug', $slug)->first()
            ?? throw new RuntimeException("Nincs ilyen workspace: {$slug} (ventmail:workspace:ensure)");

        app(WorkspaceContext::class)->set($workspace);

        return $workspace;
    }
}
