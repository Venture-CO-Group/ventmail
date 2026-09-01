<?php

declare(strict_types=1);

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | Az API-kulcs nagy entrópiájú, gépi generálású titok — nem jelszó. A bcrypt itt
 | kifejezetten rossz: lassú (mérve ~222 ms/hívás), és a random só miatt a hash NEM
 | kereshető, tehát minden kérésnél végig kellene próbálni a kulcsokat. A helyes forma:
 | SHA-256 + unique index a lookuphoz, `hash_equals` az összehasonlításhoz.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
});

it('a kulcs hash alapján közvetlenül kereshető', function () {
    ['plain' => $plain] = ApiKey::issue('CI kulcs');

    $found = ApiKey::findByPlainKey($plain);

    expect($found)->not->toBeNull();
    expect($found->name)->toBe('CI kulcs');
});

it('rossz kulccsal nincs találat', function () {
    ApiKey::issue('CI kulcs');

    expect(ApiKey::findByPlainKey('vm_live_hamis'))->toBeNull();
});

it('a keresés gyors: egyetlen indexelt lekérdezés', function () {
    ApiKey::issue('kulcs');
    ['plain' => $plain] = ApiKey::issue('masik');

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    ApiKey::findByPlainKey($plain);

    expect($queries)->toBe(1);
});

it('a nyers kulcs csak egyszer, a kiállításkor látszik', function () {
    ['model' => $model, 'plain' => $plain] = ApiKey::issue('kulcs');

    expect($plain)->toStartWith('vm_live_');
    expect($model->toArray())->not->toHaveKey('key_hash');
    expect($model->fresh()->key_hash)->not->toBe($plain);
});

it('a prefix megjeleníthető és egyedi', function () {
    ['model' => $model, 'plain' => $plain] = ApiKey::issue('kulcs');

    expect($model->prefix)->toBe(substr($plain, 0, 16));
});

it('a testmode kulcs elkülönül', function () {
    ['plain' => $plain] = ApiKey::issue('teszt kulcs', testmode: true);

    expect($plain)->toStartWith('vm_test_');
});
