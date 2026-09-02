<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * KIZÁRÓLAG fejlesztői és teszt-környezetben élő belépési út.
 *
 * Miért kell: a ventSSO OIDC-folyamatát a Playwright nem tudja végigjátszani (külső IdP,
 * valódi kliens-regisztráció). A flow-tesztek viszont hitelesített munkamenetet igényelnek.
 *
 * Miért biztonságos: a route regisztrációja környezethez kötött (lásd IdentityServiceProvider),
 * ÉS a kontroller maga is ellenőriz — így egy elszabaduló route-cache sem nyithatja meg
 * élesben. Tesztek őrzik mindkettőt.
 */
final class DevLoginController
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('A dev-belépés kizárólag fejlesztői környezetben érhető el.');
        }

        $user = User::firstOrCreate(
            ['sso_issuer' => (string) config('oidc.provider_url'), 'sso_subject' => 'dev-user'],
            ['name' => 'Fejlesztői felhasználó', 'email' => 'dev@ventmail.test']
        );

        // Az `uuid`-t a HasUuids tölti; kézzel megadva tömeges-hozzárendelési hibát adna.
        $workspace = Workspace::firstOrCreate(['slug' => 'dev'], ['name' => 'Dev workspace']);

        $workspace->members()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['roles' => [Role::Owner->value]]
        );

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('workspace_uuid', $workspace->uuid);

        return redirect()->intended($request->query('to', '/'));
    }
}
