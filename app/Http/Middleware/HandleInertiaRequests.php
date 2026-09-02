<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Minden oldalnak átadott megosztott adat.
     *
     * A felhasználó és a workspace-kontextus az M0-1-ben (Identity + Tenancy) kerül ide.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
        ];
    }
}
