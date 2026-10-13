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
    /** A lista felső korlátja (egy workspace domain-száma reálisan ennek töredéke). */
    private const MAX_DOMAINS = 500;

    /**
     * A DOMAIN-LISTA — a wizard EGY domainről szól, a küldő viszont többet tart.
     *
     * A listázás SZÁNDÉKOSAN nem `entitlement:custom_domain` mögött van (ahogy a wizard
     * megnyitása sem): a tenantnak lefokozás után is látnia kell, mi van beállítva nála.
     * A csomag-funkció azt korlátozza, hogy ÚJAT vegyen fel vagy hitelesítsen.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('domain.manage');

        $domains = Domain::query()->orderBy('name')->limit(self::MAX_DOMAINS)->get();

        // A RÉSZLETEZETT domain: a kérésben megadott, különben az első. Idegen tenant uuid-je a
        // global scope miatt nem található — ilyenkor is az első jön, nem hiba (a létezést
        // sem áruljuk el).
        $selectedUuid = $request->string('domain')->toString();
        $selected = $domains->firstWhere('uuid', $selectedUuid) ?? $domains->first();

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
                'returnPath' => (string) $domain->return_path_status,
                'verified_at' => $domain->verified_at?->toIso8601String(),
                // „N / 5 rekord rendben" — a lista ránézésre mondja meg, mennyi van hátra.
                'okCount' => count(array_filter(
                    [$domain->spf_status, $domain->dkim_status, $domain->dmarc_status, $domain->return_path_status, $domain->tracking_status],
                    static fn ($status) => in_array($status, ['verified', 'rotating'], true),
                )),
            ])->values()->all(),
            'selected' => $selected === null ? null : $this->detail($selected),
        ]);
    }

    /**
     * Egy domain RÉSZLETEI: a másolható DNS-rekordok élő állapottal és a DKIM-kulcs adatai.
     *
     * A privát kulcs SOHA nem kerül ide: a `dnsRecords()` csak a publikus kulcsot hordozza.
     *
     * @return array<string, mixed>
     */
    private function detail(Domain $domain): array
    {
        $records = $domain->dnsRecords();

        $statuses = [
            'spf' => $domain->spf_status,
            'dkim' => $domain->dkim_status,
            'dmarc' => $domain->dmarc_status,
            'return_path_transactional' => $domain->return_path_status,
            'return_path_marketing' => $domain->return_path_marketing_status,
            'tracking' => $domain->tracking_status,
        ];

        $rows = [];

        foreach ($statuses as $key => $status) {
            if (! isset($records[$key])) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'type' => (string) $records[$key]['type'],
                'name' => (string) $records[$key]['name'],
                'value' => (string) $records[$key]['value'],
                'status' => (string) $status,
                // A marketing Return-Path csak akkor kell, ha marketinget is küldenek innen.
                'optional' => in_array($key, ['return_path_marketing', 'tracking'], true),
            ];
        }

        $overlapDays = (int) config('domains.dkim_rotation_overlap_days');

        return [
            'uuid' => (string) $domain->uuid,
            'name' => (string) $domain->name,
            'status' => (string) $domain->status,
            'lastCheckedAt' => $domain->last_checked_at?->toIso8601String(),
            'records' => $rows,
            'dkim' => [
                'selector' => (string) $domain->dkim_selector,
                'signingSelector' => (string) $domain->signingSelector(),
                'previousSelector' => $domain->previousDkimIsValid() ? $domain->dkim_previous_selector : null,
                'rotatedAt' => $domain->dkim_rotated_at?->toIso8601String(),
                'previousValidUntil' => $domain->previousDkimIsValid()
                    ? $domain->dkim_rotated_at?->copy()->addDays($overlapDays)->toIso8601String()
                    : null,
                'overlapDays' => $overlapDays,
            ],
        ];
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

        return $this->backTo($domain);
    }

    public function rotateDkim(string $uuid, RotateDkimKey $rotate): RedirectResponse
    {
        Gate::authorize('domain.manage');

        $domain = Domain::query()->where('uuid', $uuid)->firstOrFail();

        $rotate($domain);

        return $this->backTo($domain)->with('success', __('ventmail.plat.dkimRotated'));
    }

    /**
     * Vissza ODA, ahonnan a művelet indult: a domain-lista részletező paneljéről (`return=index`)
     * a listára, egyébként a wizardba (a wizard e2e-folyama ezt várja).
     */
    private function backTo(Domain $domain): RedirectResponse
    {
        return request()->input('return') === 'index'
            ? redirect()->route('domains.index', ['domain' => $domain->uuid])
            : redirect()->route('domains.wizard', ['domain' => $domain->uuid]);
    }
}
