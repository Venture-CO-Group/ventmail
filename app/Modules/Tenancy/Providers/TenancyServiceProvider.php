<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Models\User;
use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Tenancy\Policies\AbilityMap;
use App\Modules\Tenancy\Policies\WorkspaceGate;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Gate;

/**
 * Tenancy modul — workspace-ek, sub-accountok, tagság, global scope, képességek.
 */
final class TenancyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Kérésenként egyetlen kontextus: a global scope és a policy ugyanazt látja.
        $this->app->scoped(WorkspaceContext::class);
    }

    protected function bootModule(): void
    {
        $gate = $this->app->make(WorkspaceGate::class);

        foreach (AbilityMap::all() as $ability) {
            Gate::define($ability, fn (User $user) => $gate->allows($user, $ability));
        }
    }
}
