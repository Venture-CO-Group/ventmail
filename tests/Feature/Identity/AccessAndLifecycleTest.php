<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSsoProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->sso = new FakeSsoProvider;
    config(['oidc.provider_url' => $this->sso->issuer, 'oidc.client_id' => 'ventmail']);
    $this->sso->fakeHttp();
});

function loginWith(array $claims = []): TestResponse
{
    /** @var FakeSsoProvider $sso */
    $sso = test()->sso;

    $sso->fakeHttp();
    test()->get('/auth/redirect');

    $sso->fakeHttp(['id_token' => $sso->idToken($claims + ['nonce' => session('oidc.nonce')])]);

    return test()->get('/auth/callback?code=c&state='.session('oidc.state'));
}

it('ventMail-jogosultság nélküli alany nem kap felhasználói sort', function () {
    // A ventSSO a teljes Venture-családé: az `aud` csak azt igazolja, hogy a token nekünk
    // szól, nem azt, hogy az alanynak van itt keresnivalója. PII-t nem tárolunk olyanról,
    // akinek nincs kapcsolata a ventMaillel (GDPR: jogalap).
    loginWith(['sub' => 'idegen-alany', 'resource_access' => []])->assertForbidden();

    expect(User::where('sso_subject', 'idegen-alany')->exists())->toBeFalse();
    expect(Auth::check())->toBeFalse();
});

it('ventmail szereppel rendelkező alany beléphet', function () {
    loginWith([
        'sub' => 'jogosult-alany',
        'resource_access' => ['ventmail' => ['roles' => ['analyst']]],
    ])->assertRedirect();

    expect(User::where('sso_subject', 'jogosult-alany')->exists())->toBeTrue();
});

it('meglévő tagsággal szerep nélkül is beléphet', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['sso_subject' => 'regi-tag']);
    $workspace->members()->create(['user_id' => $user->id, 'roles' => [Role::Editor->value]]);

    loginWith(['sub' => 'regi-tag', 'resource_access' => []])->assertRedirect();

    expect(Auth::id())->toBe($user->id);
});

it('az azonosító a kibocsátó ÉS a sub párosa', function () {
    // A `sub` csak kibocsátón belül egyedi: provider-váltáskor a puszta sub ütközhetne.
    loginWith(['sub' => 'kozos-sub', 'resource_access' => ['ventmail' => ['roles' => ['analyst']]]]);

    $user = User::where('sso_subject', 'kozos-sub')->firstOrFail();

    expect($user->sso_issuer)->toBe($this->sso->issuer);
});

it('e-mail-váltáskor a verifikáltság visszavonódik', function () {
    loginWith([
        'sub' => 'valtozo-email',
        'email' => 'regi@example.test',
        'email_verified' => true,
        'resource_access' => ['ventmail' => ['roles' => ['analyst']]],
    ]);

    expect(User::where('sso_subject', 'valtozo-email')->first()->email_verified_at)->not->toBeNull();

    Auth::logout();
    session()->flush();

    loginWith([
        'sub' => 'valtozo-email',
        'email' => 'uj@example.test',
        'email_verified' => false,
        'resource_access' => ['ventmail' => ['roles' => ['analyst']]],
    ]);

    $user = User::where('sso_subject', 'valtozo-email')->first();

    expect($user->email)->toBe('uj@example.test');
    expect($user->email_verified_at)->toBeNull('a nem verifikált új cím verifikáltnak látszik');
});

it('a kijelentkezés érvényteleníti a munkamenetet', function () {
    loginWith(['sub' => 'kilepo', 'resource_access' => ['ventmail' => ['roles' => ['analyst']]]]);

    expect(Auth::check())->toBeTrue();

    $this->post('/auth/logout')->assertRedirect();

    expect(Auth::check())->toBeFalse();
});

it('a szerep-claim nem jogosultsági forrás', function () {
    // A sessionben tárolt szerepek csak diagnosztika; hozzáférést a workspace_members ad.
    loginWith(['sub' => 'jogosult2', 'resource_access' => ['ventmail' => ['roles' => ['owner']]]]);

    $user = User::where('sso_subject', 'jogosult2')->firstOrFail();

    expect($user->workspaces()->count())->toBe(0);
});

it('a bejelentkezési folyamat rate-limitelt', function () {
    // Hitelesítetlen végpont: minden hívás session-rekordot ír és kimenő HTTP-t indít
    // a ventSSO felé. A limit IP-alapú (20/perc).
    for ($i = 0; $i < 20; $i++) {
        $this->get('/auth/redirect')->assertRedirect();
    }

    $this->get('/auth/redirect')->assertStatus(429);
});
