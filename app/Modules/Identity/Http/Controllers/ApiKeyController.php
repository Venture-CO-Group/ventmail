<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Enums\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API-KULCSOK képernyője.
 *
 * ## A NYERS KULCS EGYSZER látszik
 *
 * A tábla `key_hash`-t tárol (SHA-256, kulcsolt), visszafejteni nem lehet — és ez nem
 * kellemetlenség, hanem a lényeg. A kiállítás válaszában egyszer megmutatjuk, utána soha:
 * aki elveszíti, újat állít ki. Egy „mutasd újra" gomb azt jelentené, hogy a kulcs
 * visszanyerhető a mi oldalunkról is.
 *
 * ## A SCOPE ÉS A CSATORNA a kulcson dől el
 *
 * Egy `marketing` csatornára korlátozott kulcs nem tud tranzakciós levelet küldeni (és
 * fordítva) — a `AuthorizeChannel` middleware ezt kapuzza. A felület ezért NEM ad
 * „mindent szabad" alapértelmezést: a scope-okat és a csatornákat választani kell.
 */
final class ApiKeyController
{
    /** A kiállítható scope-ok — a `/v1` felület kapui. */
    private const SCOPES = [
        'messages:send', 'messages:read', 'events:read', 'templates:read', 'templates:write',
        'suppressions:read', 'suppressions:write', 'validate', 'routes:read', 'routes:write',
        'webhooks:read', 'webhooks:write', 'inbound:read', 'analytics:read',
    ];

    public function index(): Response
    {
        Gate::authorize('apiKey.manage');

        return Inertia::render('Identity/ApiKeys', [
            'keys' => ApiKey::query()->orderByDesc('id')->get()->map(fn (ApiKey $key) => [
                'uuid' => (string) $key->uuid,
                'name' => (string) $key->name,
                // A PREFIX az, amiről a kulcs FELISMERHETŐ a naplóban — a titok nélkül.
                'prefix' => (string) $key->prefix,
                'scopes' => array_values((array) $key->scopes),
                'channels' => $key->channels === null ? null : array_values((array) $key->channels),
                'testmode' => (bool) $key->testmode,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
            ])->values()->all(),
            'availableScopes' => self::SCOPES,
            'availableChannels' => Channel::values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('apiKey.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(self::SCOPES)],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(Channel::values())],
            'testmode' => ['boolean'],
        ]);

        $issued = ApiKey::issue(
            $validated['name'],
            array_values($validated['scopes']),
            (bool) ($validated['testmode'] ?? false),
            empty($validated['channels']) ? null : array_values($validated['channels']),
        );

        /*
         | A NYERS KULCS FLASH-BEN, egyszer.
         |
         | Nem a válasz-propban: az Inertia a props-ot a HTML-be szerializálja, és a
         | böngésző history-jában is ott maradna. A flash a session-ben él, a következő
         | kérésnél eltűnik.
         */
        return back()->with('secret', ['kind' => 'apiKey', 'value' => $issued['plain']]);
    }

    public function destroy(string $uuid): RedirectResponse
    {
        Gate::authorize('apiKey.manage');

        $key = ApiKey::query()->where('uuid', $uuid)->firstOrFail();

        // VISSZAVONÁS = TÖRLÉS. A „letiltott, de megmaradt" kulcs csak arra jó, hogy
        // valaki egyszer visszakapcsolja; a naplóban a prefix marad meg nyomnak.
        $key->delete();

        return back()->with('success', __('ventmail.apiKeys.revoked'));
    }
}
