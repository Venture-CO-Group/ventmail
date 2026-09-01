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
        foreach (AbilityMap::all() as $ability) {
            // A WorkspaceGate-et MINDIG futásidőben oldjuk fel: a WorkspaceContext `scoped`,
            // amit a queue worker két job között eldob. Egy boot-időben becsukott példány
            // a RÉGI workspace szerepeivel döntene, miközben az adat már a másikra szűrül —
            // cross-tenant jogosultság-emelés (golden rule 6).
            Gate::define($ability, fn (User $user) => $this->app->make(WorkspaceGate::class)->allows($user, $ability));
        }
    }
}
