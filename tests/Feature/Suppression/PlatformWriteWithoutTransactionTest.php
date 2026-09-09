<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\SuperAdmin\Services\GlobalSuppressions;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
 | A PLATFORM-ÍRÁS TRANZAKCIÓ NÉLKÜL — és miért NEM `RefreshDatabase` ez a fájl.
 |
 | A `RefreshDatabase` minden tesztet EGY tranzakcióba csomagol, és a `onPlatform()`
 | szándék-jelzése (`set local ventmail.platform_write`) csak tranzakcióban él. Emiatt a
 | packet minden suppression-tesztje zöld volt, miközben a security-review megmérte, hogy
 | ÉLES kérésben (`transactionLevel = 0`) a `GlobalSuppressions::remove()` a DB-triggeren
 | elhasal: „a platform-szintu suppression csak szandekos platform-irasbol torolheto".
 |
 | Vagyis a teszt-környezet maga rejtette el a hibát — a 84. kritérium feloldó ága élesben
 | halott volt. Ezért fut ez a fájl `DatabaseMigrations`-szel: valódi COMMIT-okkal,
 | tranzakciós burok NÉLKÜL, ahogy egy HTTP-kérés is fut.
 */
uses(DatabaseMigrations::class);

it('a GLOBÁLIS tiltás felvétele ÉS feloldása tranzakciós burok nélkül is működik', function () {
    // Az őr: ha ez a szám nem 0, a teszt nem azt méri, amit állít.
    expect(DB::transactionLevel())->toBe(0);

    $admin = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'admin-tranzakcio',
        'name' => 'Platform Admin',
        'email' => 'admin-tx@ventmail.app',
    ]);

    $admin->forceFill(['platform_admin' => true])->save();

    $service = app(GlobalSuppressions::class);

    $suppression = $service->add($admin, 'csapda-tx@spamtrap.example', SuppressionReason::Policy, 'SUP-50: felvétel');

    expect($suppression->workspace_id)->toBeNull()
        ->and(Suppression::query()->withoutGlobalScopes()->whereNull('workspace_id')->count())->toBe(1);

    // EZ a sor bukott el élesben — a felvétel átment (a trigger INSERT-re nem fut), a
    // feloldás nem.
    $service->remove($admin, $suppression, 'SUP-50: a felszólítás visszavonva');

    expect(Suppression::query()->withoutGlobalScopes()->whereNull('workspace_id')->count())->toBe(0);
});
