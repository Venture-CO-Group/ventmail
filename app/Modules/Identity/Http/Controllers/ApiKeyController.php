<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Enums\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    /**
     * A kiállítható scope-ok — PONTOSAN a `/v1` route-ok kapui (v1.1 · D170).
     *
     * A korábbi lista olyan scope-okat is kiadott, amiket egyetlen route sem kér
     * (`messages:read`, `routes:read`, `webhooks:read`, `inbound:read`), a `validate`-et pedig
     * rossz néven (a route `validate:run`-t vár, D14). A régi kulcsok érvényesek maradnak —
     * csak újat nem adunk ki halott scope-pal.
     */
    public const SCOPES = [
        'messages:send', 'templates:read', 'templates:write', 'events:read', 'logs:read',
        'suppressions:read', 'suppressions:write', 'webhooks:write', 'validate:run',
        'routes:write', 'analytics:read', 'journeys:write',
    ];

    /** Egy kulcs legfeljebb ennyi allowlist-bejegyzést kaphat. */
    private const MAX_ALLOWLIST = 50;

    /**
     * A VISSZAVONT kulcsokból ennyi látszik (a legutóbbiak). Az ÉLŐ kulcsok MIND látszanak
     * (RS2, D192): egy élő kulcs nem tűnhet el a listáról egy korlát miatt — azt nem lehetne
     * visszavonni.
     */
    private const MAX_REVOKED = 50;

    public function index(): Response
    {
        Gate::authorize('apiKey.manage');

        return Inertia::render('Identity/ApiKeys', [
            // Az ÉLŐ kulcsok mind, utánuk a legutóbb visszavontak (a prefix a naplóban nyom).
            'keys' => ApiKey::query()->orderByDesc('id')->get()
                ->concat(ApiKey::query()->onlyTrashed()->orderByDesc('deleted_at')->limit(self::MAX_REVOKED)->get())
                ->map(fn (ApiKey $key) => [
                'uuid' => (string) $key->uuid,
                'name' => (string) $key->name,
                // A PREFIX az, amiről a kulcs FELISMERHETŐ a naplóban — a titok nélkül.
                'prefix' => (string) $key->prefix,
                'scopes' => array_values((array) $key->scopes),
                'channels' => $key->channels === null ? null : array_values((array) $key->channels),
                'testmode' => (bool) $key->testmode,
                'ip_allowlist' => array_values((array) $key->ip_allowlist),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
                'revoked_at' => $key->deleted_at?->toIso8601String(),
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
            // Vesszővel / sortöréssel / szóközzel elválasztott IP-k és CIDR-tartományok.
            'ip_allowlist' => ['nullable', 'string', 'max:4000'],
        ]);

        $allowlist = self::parseAllowlist((string) ($validated['ip_allowlist'] ?? ''));

        if ($allowlist === null) {
            throw ValidationException::withMessages(['ip_allowlist' => __('ventmail.plat.invalidAllowlist')]);
        }

        $issued = ApiKey::issue(
            $validated['name'],
            array_values($validated['scopes']),
            (bool) ($validated['testmode'] ?? false),
            empty($validated['channels']) ? null : array_values($validated['channels']),
            $allowlist,
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

        // Már visszavont kulcsra 404: a soft-delete scope miatt nem található.
        $key = ApiKey::query()->where('uuid', $uuid)->firstOrFail();

        // VISSZAVONÁS = TÖRLÉS. A „letiltott, de megmaradt" kulcs csak arra jó, hogy
        // valaki egyszer visszakapcsolja; a naplóban a prefix marad meg nyomnak.
        $key->delete();

        return back()->with('success', __('ventmail.apiKeys.revoked'));
    }

    /**
     * Az allowlist szövegének bontása és ellenőrzése.
     *
     * `null` = érvénytelen bejegyzés (a hívó validációs hibát ad); üres lista = bármely cím.
     * Csak valódi IP (v4/v6) vagy CIDR fogadható el, ésszerű prefix-hosszal — a `0.0.0.0/0`
     * szándékos „mindenki" ugyanaz, mint az üres lista, ezért azt is elfogadjuk.
     *
     * @return list<string>|null
     */
    public static function parseAllowlist(string $raw): ?array
    {
        $entries = array_values(array_unique(array_filter(
            preg_split('/[\s,;]+/', trim($raw)) ?: [],
            static fn (string $entry): bool => $entry !== '',
        )));

        if (count($entries) > self::MAX_ALLOWLIST) {
            return null;
        }

        foreach ($entries as $entry) {
            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

            $isV4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6 = ! $isV4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

            if (! $isV4 && ! $isV6) {
                return null;
            }

            if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > ($isV4 ? 32 : 128))) {
                return null;
            }
        }

        return $entries;
    }
}
