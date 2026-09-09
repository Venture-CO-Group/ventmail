<?php

declare(strict_types=1);

namespace App\Modules\Domains\Http\Controllers;

use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Actions\RotateDkimKey;
use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Domain-műveletek. Mind POST: állapotot módosítanak (CSRF-védelem, idempotencia).
 *
 * A jogosultság ITT dől el: a route-stack (web + auth + workspace) csak TAGSÁGOT bizonyít,
 * szerepet nem — enélkül egy `analyst` (olvasó) is felvehetne domaint és generálhatna
 * 2048 bites kulcsanyagot.
 */
final class DomainController
{
    /**
     * A DOMAIN-LISTA — a wizard EGY domainről szól, a küldő viszont többet tart.
     *
     * A listázás SZÁNDÉKOSAN nem `entitlement:custom_domain` mögött van (ahogy a wizard
     * megnyitása sem): a tenantnak lefokozás után is látnia kell, mi van beállítva nála.
     * A csomag-funkció azt korlátozza, hogy ÚJAT vegyen fel vagy hitelesítsen.
     */
    public function index(): Response
    {
        Gate::authorize('domain.manage');

        $domains = Domain::query()->orderBy('name')->get();

        return Inertia::render('Domains/Index', [
            'domains' => $domains->map(fn (Domain $domain) => [
                'uuid' => (string) $domain->uuid,
                'name' => (string) $domain->name,
                'status' => (string) $domain->status,
                // A NÉGY RÉSZ-ÁLLAPOT külön is látszik: a „pending" önmagában nem mondja
                // meg, MELYIK rekord hiányzik, és a támogatás fél óráját az viszi el.
                'spf' => (string) $domain->spf_status,
                'dkim' => (string) $domain->dkim_status,
                'dmarc' => (string) $domain->dmarc_status,
                'tracking' => (string) $domain->tracking_status,
                'verified_at' => $domain->verified_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, CreateDomain $createDomain): RedirectResponse
    {
        Gate::authorize('domain.manage');

        $validated = $request->validate([
            // RFC 1035: címkénként max 63, összesen max 253 karakter; legalább egy pont.
            // A validáció NEM ASCII-only: a tulajdonosi döntés szerint az IDN kell (a bérlő
            // a `példa.hu`-t akarja beírni, nem a punycode-ját). Az érdemi ellenőrzés a
            // `DomainNormalizer`-é — ő az egyetlen hely, ahol a kanonikus alak eldől.
            'name' => [
                'required',
                'string',
                'max:253',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (DomainNormalizer::canonical(trim((string) $value)) === '') {
                        $fail('Érvénytelen domain-név.');
                    }
                },
            ],
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
