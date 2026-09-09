<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Policies\AbilityMap;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

/**
 * Minden oldalnak átadott megosztott adat — a felület KÖZÖS ÁLLAPOTA.
 *
 * ## Miért itt, és miért ennyi
 *
 * A navigáció, a workspace-váltó és a jogosultság-függő menüpontok MINDEN képernyőn
 * kellenek. Ha ezt oldalanként adnánk át, az első kifelejtett kontroller egy olyan
 * képernyőt adna, ahol nincs menü — vagy ami rosszabb, ahol a menü olyat mutat, amihez a
 * belépőnek nincs joga.
 *
 * ## A KÉPESSÉGEK a szerverről jönnek, és NEM ők döntenek
 *
 * A `abilities` lista arra való, hogy a felület ELREJTSE, amihez nincs jog — nem arra,
 * hogy engedélyezzen. A tényleges kaput minden kontroller `Gate::authorize()`-zal húzza
 * meg: egy elrejtett menüpont URL-jét bárki beírhatja.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /*
         | MINDEN KONTEXTUS-FÜGGŐ MEZŐ LUSTA (closure), és ez nem optimalizálás.
         |
         | Az Inertia middleware a `web` CSOPORTBAN fut, a `auth` és a `workspace` viszont
         | ROUTE-middleware — vagyis a `share()` HAMARABB hívódik, mint hogy a
         | felhasználó és a workspace-kontextus egyáltalán feloldódna. Mérve: közvetlen
         | kiértékeléssel a `workspace` prop `null` volt, a `abilities` pedig ÜRES — a menü
         | tehát minden képernyőn üresen jelent volna meg, jogosultság ide vagy oda.
         |
         | A closure-öket az Inertia a VÁLASZ összeállításakor értékeli ki, akkorra pedig a
         | route-middleware már lefutott.
         */
        return [
            ...parent::share($request),
            'appName' => config('app.name'),

            'auth' => fn (): array => [
                'user' => ($user = $request->user()) instanceof User ? [
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->preferredLocale(),
                    'platformAdmin' => $user->isPlatformAdmin(),
                ] : null,
            ],

            'workspace' => function (): ?array {
                $context = app(WorkspaceContext::class);

                if (! $context->hasWorkspace()) {
                    return null;
                }

                $workspace = $context->workspace();

                return ['uuid' => (string) $workspace->uuid, 'name' => (string) $workspace->name];
            },

            // A VÁLTÓ csak a SAJÁT tagságokat kínálja: a lista maga is jogosultsági
            // felület, nem kényelmi funkció.
            'workspaces' => fn (): array => ($user = $request->user()) instanceof User
                ? $this->membershipsOf($user)
                : [],

            'abilities' => fn (): array => $this->abilitiesFor($request->user()),

            /*
             | A TÁMOGATÓI NÉZET LÁTHATÓ. Ez nem díszítés: az admin tudja, hogy IDEGEN fiók
             | adatait nézi (nehogy a sajátjának higgye), és azt is, hogy írni nem fog —
             | különben a tiltást hibának nézné.
             */
            'impersonation' => fn (): ?array => $this->impersonationOf($request->user()),

            'locale' => fn (): string => app()->getLocale(),
            'supportedLocales' => array_values((array) config('app.supported_locales', [])),

            'flash' => fn (): array => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),

                /*
                 | AZ EGYSZER MEGMUTATOTT TITOK (API-kulcs, SMTP-jelszó, webhook-secret).
                 |
                 | Miért a flash-ben és miért NEM oldal-propban: a props a HTML-be
                 | szerializálódik, tehát a böngésző history-jában és a lap forrásában is
                 | ott maradna — egy visszalépés újra megmutatná. A flash a session-ben él,
                 | és a következő kérésnél eltűnik.
                 |
                 | Egy közös kulcs, nem képernyőnként külön: a szabály („egyszer látszik,
                 | utána soha") azonos mindhárom titokra, és három külön propból előbb-utóbb
                 | kimaradna valamelyik.
                 */
                'secret' => $request->session()->get('secret'),
            ],
        ];
    }

    /** @return list<array{uuid: string, name: string, roles: list<string>}> */
    private function membershipsOf(User $user): array
    {
        return WorkspaceMember::query()
            ->where('user_id', $user->getKey())
            ->with('workspace')
            ->get()
            ->filter(fn (WorkspaceMember $member) => $member->workspace !== null)
            ->map(fn (WorkspaceMember $member) => [
                'uuid' => (string) $member->workspace->uuid,
                'name' => (string) $member->workspace->name,
                'roles' => array_values($member->roles()),
            ])
            ->values()
            ->all();
    }

    /**
     * A KÉPESSÉGEK az aktuális workspace-re. A `Gate` a forrás, nem az `AbilityMap`
     * közvetlenül: így a támogatói nézet olvasási joga (`WorkspaceGate`) is látszik a
     * menüben — különben az admin egy üres felületet kapna, tele elrejtett menüponttal.
     *
     * @return list<string>
     */
    private function abilitiesFor(mixed $user): array
    {
        if (! $user instanceof User || ! app(WorkspaceContext::class)->hasWorkspace()) {
            return [];
        }

        return array_values(array_filter(
            AbilityMap::all(),
            static fn (string $ability): bool => Gate::forUser($user)->allows($ability)
        ));
    }

    /** @return array{active: bool, workspace: string, expiresAt: string}|null */
    private function impersonationOf(mixed $user): ?array
    {
        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            return null;
        }

        $session = app(Impersonation::class)->active($user);

        if ($session === null) {
            return null;
        }

        return [
            'active' => true,
            'workspace' => (string) ($session->workspace?->name ?? ''),
            'expiresAt' => $session->expires_at->toIso8601String(),
        ];
    }
}
