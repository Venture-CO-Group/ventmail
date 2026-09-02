<?php

declare(strict_types=1);

namespace App\Modules\Domains\Http\Controllers;

use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A domain-hitelesítő wizard képernyője.
 *
 * A privát kulcs SOHA nem kerül a válaszba: a `dnsRecords()` csak a publikus kulcsot
 * tartalmazza, a modell `$hidden`-je pedig a szerializálást is zárja.
 */
final class DomainWizardController
{
    public function __invoke(Request $request, CreateDomain $createDomain): Response
    {
        // A tagság nem elég: a küldő infrastruktúra képernyője szerephez kötött.
        Gate::authorize('domain.manage');

        // A wizard EGY domainre vonatkozik, és a domaint a hívó adja meg — nem a
        // „legutóbbi" sort találgatjuk. (Találgatva a képernyő állapota attól függne, ki mit
        // hozott létre korábban, és a flow-tesztek is egymásra hatnának.)
        $domain = $request->filled('domain')
            ? Domain::query()->where('uuid', $request->string('domain'))->first()
            : null;

        // Demo-mód: a mockup- és e2e-ellenőrzéshez kell egy kitöltött állapot VALÓDI
        // DNS-rekordokkal (a generátor ugyanaz, mint élesben). KIZÁRÓLAG fejlesztői
        // környezetben: élesben egy GET-kérés így entitást és kulcsanyagot hozna létre.
        if ($request->boolean('demo')) {
            abort_unless(app()->environment('local', 'testing'), 404);

            $domain ??= Domain::query()->where('name', 'demo.pelda.hu')->first()
                ?? $createDomain('demo.pelda.hu');
        }

        return Inertia::render('Domains/Wizard', [
            'domain' => $domain === null ? null : [
                'uuid' => $domain->uuid,
                'name' => $domain->name,
                'status' => $domain->status,
            ],
            'records' => $domain === null ? [] : $this->records($domain),
        ]);
    }

    /** @return array<string, array<string, string>> */
    private function records(Domain $domain): array
    {
        $statuses = [
            'spf' => $domain->spf_status,
            'dkim' => $domain->dkim_status,
            'dmarc' => $domain->dmarc_status,
            'tracking' => $domain->tracking_status,
        ];

        return collect($domain->dnsRecords())
            ->map(fn (array $record, string $key) => [...$record, 'status' => $statuses[$key] ?? 'pending'])
            ->all();
    }
}
