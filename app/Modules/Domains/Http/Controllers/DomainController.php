<?php

declare(strict_types=1);

namespace App\Modules\Domains\Http\Controllers;

use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Actions\RotateDkimKey;
use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Domain-műveletek. Mind POST: állapotot módosítanak (CSRF-védelem, idempotencia).
 *
 * A jogosultság ITT dől el: a route-stack (web + auth + workspace) csak TAGSÁGOT bizonyít,
 * szerepet nem — enélkül egy `analyst` (olvasó) is felvehetne domaint és generálhatna
 * 2048 bites kulcsanyagot.
 */
final class DomainController
{
    public function store(Request $request, CreateDomain $createDomain): RedirectResponse
    {
        Gate::authorize('domain.manage');

        $validated = $request->validate([
            // RFC 1035: címkénként max 63, összesen max 253 karakter; legalább egy pont.
            'name' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ]);

        $domain = $createDomain($validated['name']);

        return redirect()->route('domains.wizard', ['domain' => $domain->uuid]);
    }

    public function verify(string $uuid, VerifyDomainDns $verify): RedirectResponse
    {
        Gate::authorize('domain.manage');

        // A global scope miatt idegen tenant uuid-jére nem találunk — 404, nem 403:
        // a létezést sem áruljuk el.
        $domain = Domain::query()->where('uuid', $uuid)->firstOrFail();

        $verify->handle($domain);

        return redirect()->route('domains.wizard', ['domain' => $domain->uuid]);
    }

    public function rotateDkim(string $uuid, RotateDkimKey $rotate): RedirectResponse
    {
        Gate::authorize('domain.manage');

        $domain = Domain::query()->where('uuid', $uuid)->firstOrFail();

        $rotate($domain);

        return redirect()->route('domains.wizard', ['domain' => $domain->uuid]);
    }
}
