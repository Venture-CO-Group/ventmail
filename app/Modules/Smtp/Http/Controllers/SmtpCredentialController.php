<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Http\Controllers;

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Smtp\Models\SmtpCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SMTP-HITELESÍTŐK képernyője (golden rule 3: nyílt relay TILOS).
 *
 * ## Amit ez a képernyő NEM tesz
 *
 * Nem mutat jelszót. A `password_hash` bcrypt, visszafejthetetlen — a kiállításkor
 * egyszer látszik, utána soha. Aki elveszíti, ROTÁL: új hitelesítőt kap, a régi
 * visszavonva. Ez nem kényelmetlenség, hanem az, ami miatt egy kiszivárgott adatbázis
 * nem ad relay-hozzáférést.
 *
 * ## Amit MEGMUTAT, mert enélkül vakon üzemeltetsz
 *
 * A csatorna-korlátozást (egy `marketing` hitelesítő nem küldhet tranzakciósat), a
 * per-hitelesítő percenkénti keretet, és az utolsó használat idejét — ez utóbbi az, amiből
 * kiderül, hogy egy régi hitelesítő elfelejtve, de ÉLŐEN ott maradt.
 */
final class SmtpCredentialController
{
    public function index(): Response
    {
        Gate::authorize('smtpCredential.manage');

        return Inertia::render('Smtp/Credentials', [
            'credentials' => SmtpCredential::query()
                ->with('domain')
                ->orderByDesc('id')
                ->get()
                ->map(fn (SmtpCredential $credential) => [
                    'uuid' => (string) $credential->uuid,
                    'name' => (string) $credential->name,
                    'username' => (string) $credential->username,
                    'domain' => $credential->domain?->name,
                    'channel' => (string) $credential->channel_restriction,
                    'rateLimit' => (int) $credential->rate_limit_per_minute,
                    'requireTls' => (bool) $credential->require_tls,
                    'revokedAt' => $credential->revoked_at?->toIso8601String(),
                    'lastUsedAt' => $credential->last_used_at?->toIso8601String(),
                ])->values()->all(),

            // A hitelesítő domainhez KÖTHETŐ: így egy kiszivárgott hitelesítő sem tud más
            // domain nevében küldeni (a feladó-domain a hitelesítőből is korlátozódik).
            'domains' => Domain::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Domain $domain) => ['uuid' => (string) $domain->uuid, 'name' => (string) $domain->name])
                ->values()->all(),
            'channels' => Channel::values(),
            'defaultRateLimit' => (int) config('smtp.rate_limit.per_credential_per_minute', 60),
            // A beküldés PARAMÉTEREI — a fejlesztő ezt keresi elsőnek. Plaintext port nincs.
            'submission' => [
                'host' => (string) config('smtp.hostname'),
                'ports' => [['port' => 587, 'tls' => 'STARTTLS'], ['port' => 465, 'tls' => 'TLS']],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('smtpCredential.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['nullable', 'string'],
            'channel' => ['required', 'string', Rule::in(Channel::values())],
        ]);

        $domainId = null;

        if (! empty($validated['domain'])) {
            // A domaint UUID alapján oldjuk fel, a global scope-on keresztül: idegen
            // workspace domainjére így nem lehet hitelesítőt kötni.
            $domainId = Domain::query()->where('uuid', $validated['domain'])->firstOrFail()->getKey();
        }

        $issued = SmtpCredential::issue($validated['name'], $domainId, $validated['channel']);

        // A JELSZÓ FLASH-BEN, egyszer — ugyanaz az érv, mint az API-kulcsnál.
        return back()->with('secret', [
            'kind' => 'smtp',
            'username' => $issued['username'],
            'password' => $issued['password'],
        ]);
    }

    /**
     * ROTÁCIÓ (v1.1 · 7. modul): új jelszó UGYANAZZAL a névvel, domainnel, csatornával és
     * kerettel; a régi hitelesítő AZONNAL visszavonva.
     *
     * Nincs átfedési ablak — szándékosan: a rotációt jellemzően szivárgás váltja ki, és egy
     * relay-hitelesítőnél a „még 24 óráig él" a támadónak ad 24 óra nyílt küldést. Az
     * ügyfél a válaszban kapott új felhasználónév/jelszó párt azonnal beírja.
     *
     * A régi sor `FOR UPDATE` alatt: két párhuzamos rotáció különben két élő utódot adna.
     */
    public function rotate(string $uuid): RedirectResponse
    {
        Gate::authorize('smtpCredential.manage');

        $issued = DB::transaction(function () use ($uuid): array {
            $old = SmtpCredential::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            $issued = SmtpCredential::issue((string) $old->name, $old->domain_id, (string) $old->channel_restriction);

            $issued['credential']->forceFill([
                'rotated_from_id' => $old->getKey(),
                'rate_limit_per_minute' => $old->rate_limit_per_minute,
                'require_tls' => $old->require_tls,
            ])->save();

            $old->forceFill(['revoked_at' => now()])->save();
            $old->delete();

            return $issued;
        });

        return back()->with('secret', [
            'kind' => 'smtp',
            'username' => $issued['username'],
            'password' => $issued['password'],
        ]);
    }

    public function destroy(string $uuid): RedirectResponse
    {
        Gate::authorize('smtpCredential.manage');

        $credential = SmtpCredential::query()->where('uuid', $uuid)->firstOrFail();

        /*
         | VISSZAVONÁS, nem törlés.
         |
         | A hitelesítő SOFT DELETE-es, és a `revoked_at` külön mező: a naplóban szereplő
         | korábbi SMTP-munkamenetek így visszakereshetők maradnak („melyik hitelesítővel
         | ment ki ez a levél?"). Egy kemény törlés a nyomot is elvinné.
         */
        $credential->forceFill(['revoked_at' => now()])->save();
        $credential->delete();

        return back()->with('success', __('ventmail.smtp.revoked'));
    }
}
