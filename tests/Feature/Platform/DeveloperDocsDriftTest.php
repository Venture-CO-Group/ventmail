<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Api\Services\ApiReference;
use App\Modules\Api\Services\DeveloperGuides;
use App\Modules\Api\Support\ApiRateLimits;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/*
 | UI-POLÍROZÁS · 9. pont — FEJLESZTŐI DOKSI: DRIFT = 0.
 |
 | (1) Minden dokumentált végpont szerepel az OpenAPI-ban ÉS fordítva — a képernyő propjai és a
 |     szerződés független feldolgozása halmazként egyezik, és mindegyik él `/v1` route-ként.
 | (2) A prózai útmutatók EGY forrásfájlból jönnek (`docs/developer/guides.yaml`), a számok a
 |     futó kódból — ismeretlen helyőrző nincs, és a kitöltött érték az, ami fut.
 */

/** @return list<string> `METHOD /path` a szerződésből, a referenciától FÜGGETLENÜL feldolgozva. */
function contractOperations(): array
{
    $spec = Yaml::parseFile(base_path('docs/openapi/ventmail-v1.yaml'));
    $out = [];

    foreach ($spec['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $out[] = strtoupper($method).' '.$path;
            }
        }
    }

    sort($out);

    return $out;
}

beforeEach(function () {
    $this->withoutVite();
});

function docsProps($test): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => [Role::Developer->value]]);

    $props = [];

    $test->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/developers')
        ->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];

            return $page->component('Platform/Docs');
        });

    return $props;
}

it('DRIFT = 0: a doksi végpontjai pontosan a szerződés végpontjai, és mind él /v1 route-ként', function () {
    $props = docsProps($this);

    $documented = collect($props['reference']['operations'])->map(fn ($op) => $op['method'].' '.$op['path'])->sort()->values()->all();

    expect($documented)->toBe(contractOperations());

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'v1/'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => $m.' /'.preg_replace('/\{(\w+)\??\}/', '{$1}', substr($route->uri(), 3))))
        ->all();

    foreach ($documented as $operation) {
        expect($routes)->toContain($operation);
    }
});

it('a „hamarosan" végpontok (bejövő, címellenőrzés) a referenciában vannak, jelölve — újat nem találunk ki', function () {
    $operations = collect(app(ApiReference::class)->build()['operations']);

    $soon = $operations->where('soon', true)->map(fn ($op) => $op['path'])->unique()->values();

    expect($soon)->not->toBeEmpty();
    foreach ($soon as $path) {
        expect(ApiReference::isSoon($path))->toBeTrue();
    }
    expect($operations->firstWhere('path', '/messages')['soon'])->toBeFalse();
});

it('a hibakódok és a vezérlő-paraméterek a szerződésből generáltak', function () {
    $spec = Yaml::parseFile(base_path('docs/openapi/ventmail-v1.yaml'));
    $reference = app(ApiReference::class)->build();

    expect($reference['errorCodes'])->toBe($spec['components']['schemas']['ErrorCode']['enum']);

    $controls = collect($reference['controls'])->pluck('name');

    expect($controls)->toContain('o:testmode', 'o:tag', 'o:deliverytime', 'o:channel', 'recipient-variables', 't:variables', 'h:X-* · v:*');
});

it('az útmutatók EGY forrásfájlból jönnek, minden útmutató hu + en, ismeretlen helyőrző nincs', function () {
    $source = Yaml::parseFile(base_path(DeveloperGuides::SOURCE));

    $keys = collect($source['guides'])->pluck('key')->all();

    expect($keys)->toBe(['auth', 'messages', 'smtp', 'webhooks', 'errors', 'rateLimits', 'sandbox', 'inbound', 'validation']);

    foreach ($source['guides'] as $guide) {
        expect($guide['title'])->toHaveKeys(['hu', 'en'])
            ->and($guide['body'])->toHaveKeys(['hu', 'en']);
    }

    foreach (['hu', 'en', 'de'] as $locale) {
        foreach (app(DeveloperGuides::class)->build($locale, 'https://api.ventmail.app/v1') as $guide) {
            expect($guide['html'])->not->toContain('{{')->not->toContain('<script');
        }
    }

    // A többi felületi nyelv az angolt kapja.
    expect(app(DeveloperGuides::class)->build('de', 'x')[0]['title'])->toBe('Authentication and scopes');
});

it('a kitöltött számok a FUTÓ kódból jönnek (rate-limit, SMTP, webhook-újrapróbálás)', function () {
    config(['smtp.hostname' => 'smtp.teszt.example', 'smtp.rate_limit.per_credential_per_minute' => 42]);

    $guides = collect(app(DeveloperGuides::class)->build('hu', 'https://api.ventmail.app/v1'))->keyBy('key');

    expect($guides['rateLimits']['html'])->toContain((string) ApiRateLimits::PER_KEY)->toContain((string) ApiRateLimits::PER_PEER)->toContain('42')
        ->and($guides['smtp']['html'])->toContain('smtp.teszt.example')->toContain('587')->toContain('465')
        ->and($guides['webhooks']['html'])->toContain((string) DeliverWebhook::MAX_ATTEMPTS)->toContain('1 → 2 → 4 → 8 → 16 min')
        ->and($guides['inbound']['soon'])->toBeTrue()
        ->and($guides['validation']['soon'])->toBeTrue()
        ->and($guides['messages']['html'])->toContain('recipient-variables');
});

it('a képernyő propjai: útmutatók, scope-lista, hibakódok', function () {
    $props = docsProps($this);

    expect(collect($props['guides'])->pluck('key')->all())->toContain('smtp', 'rateLimits', 'webhooks')
        ->and($props['scopes'])->toContain('messages:send', 'messages:send:marketing', 'validate:run')
        ->and($props['reference']['errorCodes'])->toContain('SUPPRESSED', 'NO_CONSENT');
});
