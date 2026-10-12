<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\SyncSsoUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 | C2 · 89. kritérium — a NYELV, ami a ventSSO claimjéből jön.
 |
 | Miért külön fájl: a `LocaleCompletenessTest` a `lang/` fájlokat olvassa, adatbázis nélkül.
 | Ez a teszt VISZONT ír a `users` táblába, tehát `RefreshDatabase` kell hozzá — és a
 | trait-et fájl-szinten kell kérni. A teszt-szintű `->uses(...)` a CI-ban NEM futtatta le a
 | migrációkat (mérve: „relation »users« does not exist"), lokálisan pedig csak azért ment
 | át, mert a teszt-adatbázisban egy korábbi futás sémája állt.
 */

it('az IdP `locale` claimje csak TÁMOGATOTT nyelvet írhat a felhasználóra', function () {
    /*
     | A claim IDEGEN BEMENET: az IdP bármit adhat (`de-CH`, `zz`, path-szerű sztringet), és
     | eddig ellenőrzés nélkül a `users.locale`-be került. A Laravel a furcsa értékre később
     | `InvalidArgumentException`-t dob (mérve), a felhasználó pedig egy nem létező nyelvet
     | „választ" — ami a felületen üres feliratokat jelentene.
     |
     | A régió-változat elfogadható, ha az alapnyelv támogatott: `hu-HU` → `hu`.
     */
    $sync = app(SyncSsoUser::class);

    $esetek = [
        'de' => 'de',
        'hu-HU' => 'hu',
        'en_US' => 'en',
        'zz' => (string) config('app.locale'),
        '../../etc/passwd' => (string) config('app.locale'),
        '' => (string) config('app.locale'),
    ];

    foreach ($esetek as $claim => $vart) {
        $user = $sync([
            'iss' => 'https://sso.teszt.invalid',
            'sub' => 'alany-'.md5((string) $claim),
            'email' => 'nyelv-'.md5((string) $claim).'@pelda.hu',
            'email_verified' => true,
            'name' => 'Teszt',
            'locale' => $claim,
        ]);

        expect($user->locale)->toBe($vart)
            ->and(config('app.supported_locales'))->toContain($user->locale);
    }
})->uses(RefreshDatabase::class);

it('a BEJELENTKEZETT felhasználó nyelve dönti el a kérés nyelvét', function () {
    /*
     | A `SetLocale` middleware nélkül a `users.locale` egy oszlop volt, amit SEMMI nem
     | olvasott: a review megmérte, hogy az egész kódbázisban nulla `setLocale()` hívás
     | volt, tehát minden válasz a config nyelvén jött. A 89. kritérium („hat nyelven")
     | formálisan teljesült, gyakorlatilag nem.
     |
     | A felhasználó választása ERŐSEBB, mint amit a böngésző hirdet: az `Accept-Language`
     | a gép beállítása, a `locale` az övé.
     */
    Route::middleware(['web', 'auth'])->get('/teszt/nyelv', fn () => ['locale' => app()->getLocale()]);

    $user = User::factory()->create(['locale' => 'de', 'locale_source' => 'user']);

    $this->actingAs($user)
        ->withHeaders(['Accept-Language' => 'it'])
        ->get('/teszt/nyelv')
        ->assertOk()
        ->assertJson(['locale' => 'de']);

    // NEM TÁMOGATOTT tárolt érték esetén az `Accept-Language` jön, majd az alap.
    $user->forceFill(['locale' => 'zz'])->save();

    $this->actingAs($user)
        ->withHeaders(['Accept-Language' => 'it'])
        ->get('/teszt/nyelv')
        ->assertJson(['locale' => 'it']);

    $this->actingAs($user)->get('/teszt/nyelv')->assertJson(['locale' => (string) config('app.locale')]);
});
