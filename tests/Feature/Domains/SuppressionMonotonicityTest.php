<?php

declare(strict_types=1);

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A suppression MONOTON: a hatókör és a lejárat csak SZIGORODHAT.
 |
 | Enélkül egy `suppressions:write` kulccsal gyengíteni lehetne: egy `all` hatókörű
 | FBL-panaszt `marketing`-re szűkítve a tranzakciós csatorna újra kinyílna a panaszkodó
 | címre, egy lejáró soft-bounce-ra érkező panasz pedig örökölné a lejáratot.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    ['model' => $this->key] = ApiKey::issue('CI', ['suppressions:read', 'suppressions:write']);
});

it('a hatókör nem szűkíthető', function () {
    app(SuppressionStore::class)->add($this->key, 'x@example.test', 'complaint', 'all');
    app(SuppressionStore::class)->add($this->key, 'x@example.test', 'policy', 'marketing');

    expect(Suppression::first()->applies_to)->toBe('all');
});

it('a hatókör bővíthető akkor is, ha az ok gyengébb', function () {
    app(SuppressionStore::class)->add($this->key, 'x@example.test', 'complaint', 'marketing');
    app(SuppressionStore::class)->add($this->key, 'x@example.test', 'unsubscribe', 'all');

    $row = Suppression::first();

    expect($row->applies_to)->toBe('all');
    // A súlyosabb OK viszont megmarad.
    expect($row->reason)->toBe('complaint');
});

it('a súlyosabb ok törli a lejáratot', function () {
    app(SuppressionStore::class)->add($this->key, 'x@example.test', 'bounce', 'all', now()->addDay());
    app(SuppressionStore::class)->add($this->key, 'x@example.test', 'complaint');

    expect(Suppression::first()->expires_at)->toBeNull();
});

it('ismeretlen ok és hatókör elutasítva', function () {
    expect(fn () => app(SuppressionStore::class)->add($this->key, 'x@example.test', 'akarmi'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(SuppressionStore::class)->add($this->key, 'x@example.test', 'bounce', 'marketting'))
        ->toThrow(InvalidArgumentException::class);
});

it('a globális tiltás nem írható felül tenantból', function () {
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@example.test', 'reason' => 'policy']));

    expect(fn () => app(SuppressionStore::class)->add($this->key, 'globalis@example.test', 'bounce'))
        ->toThrow(AuthorizationException::class);
});

it('panasz és leiratkozás API-ból nem törölhető', function () {
    // A tenant nem törölheti a saját panasz-előzményét, hogy újra küldhessen.
    app(SuppressionStore::class)->add($this->key, 'panasz@example.test', 'complaint');
    app(SuppressionStore::class)->add($this->key, 'leiratkozott@example.test', 'unsubscribe');
    app(SuppressionStore::class)->add($this->key, 'bounce@example.test', 'bounce');

    expect(fn () => app(SuppressionStore::class)->remove($this->key, 'panasz@example.test'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SuppressionStore::class)->remove($this->key, 'leiratkozott@example.test'))
        ->toThrow(AuthorizationException::class);

    expect(app(SuppressionStore::class)->remove($this->key, 'bounce@example.test'))->toBeTrue();
});

it('a lista lapozott', function () {
    for ($i = 0; $i < 30; $i++) {
        app(SuppressionStore::class)->add($this->key, "cim{$i}@example.test", 'bounce');
    }

    $page = app(SuppressionStore::class)->list($this->key, perPage: 10);

    expect($page->count())->toBe(10);
    expect($page->hasMorePages())->toBeTrue();
});
