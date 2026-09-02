<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        // KÉT FÜGGETLEN kapcsoló: az APP_ENV önmagában nem elég, mert az `.env.example`
        // alapértéke `local` — aki a sablonból indul egy szerveren, alapból nyitva hagyná.
        // A `abort(404)` szándékos: a kivétel 500-zal elárulná, hogy a végpont létezik.
        abort_unless(
            config('identity.dev_login_enabled') && ! app()->isProduction() && app()->environment('local', 'testing'),
            404
        );

        // Dedikált, nem létező issuer: így a dev-user SOHA nem ütközhet egy valódi
        // ventSSO-alannyal, még akkor sem, ha valaki élesre állított providerrel indítja.
        $user = User::firstOrCreate(
            ['sso_issuer' => 'https://dev.invalid', 'sso_subject' => 'dev-user'],
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

        // Csak RELATÍV cél: a `?to=https://tamado.example` különben nyílt átirányítás lenne,
        // bejelentkeztetéssel egybekötve.
        $to = (string) $request->query('to', '/');
        $safe = str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : '/';

        return redirect()->intended($safe);
    }
}
