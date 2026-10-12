<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DnsRecordGenerator;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Drivers\PostalDriver;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 | KÉT POOL, SOHA NEM KEVEREDVE (deploy-ovh, kemény szabály 5).
 |
 | A reputáció az IP-hez tapad: ha egyetlen kampány-levél a tranzakciós IP-ről megy ki,
 | annak spam-jelölése a jelszó-visszaállító levelet is viszi. Élesben a két pool KÉT
 | külön Postal-példány (külön IP, HELO, Return-Path domain) — ez a teszt azt bizonyítja,
 | hogy a ventMail a csatorna alapján MINDIG a jó példányt hívja, és más út nincs.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    config([
        'sending.postal.endpoint' => '',
        'sending.postal.api_key' => '',
        'sending.postal.pools' => [
            'transactional' => [
                'endpoint' => 'http://postal-tx:5000',
                'api_key' => 'tx-kulcs',
                'return_path_prefix' => 'psrp-tx',
                'return_path_domain' => 'rp-tx.ventmail.test',
            ],
            'marketing' => [
                'endpoint' => 'http://postal-mk:5000',
                'api_key' => 'mk-kulcs',
                'return_path_prefix' => 'psrp-mk',
                'return_path_domain' => 'rp-mk.ventmail.test',
            ],
        ],
    ]);

    Http::fake([
        'postal-tx:5000/*' => Http::response(['status' => 'success', 'data' => ['message_id' => 'tx-1']]),
        'postal-mk:5000/*' => Http::response(['status' => 'success', 'data' => ['message_id' => 'mk-1']]),
    ]);
});

function pooled(string $channel, array $overrides = []): Message
{
    $message = Message::create(array_merge([
        'channel' => $channel,
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'recipients' => ['cimzett@example.test'],
        'options' => ['body_html' => '<p>Szia</p>'],
    ], $overrides));

    return $message->withRenderedBodies(['cimzett@example.test' => ['html' => '<p>Szia</p>', 'text' => 'Szia']]);
}

it('minden csatorna pontosan egy pool-osztályba tartozik', function () {
    expect(Channel::Transactional->poolClass())->toBe('transactional');
    expect(Channel::Marketing->poolClass())->toBe('marketing');
    expect(Channel::Campaign->poolClass())->toBe('marketing');
    expect(Channel::Journey->poolClass())->toBe('marketing');
});

it('a transactional üzenet KIZÁRÓLAG a tranzakciós példányra megy', function () {
    $result = (new PostalDriver)->send(pooled('transactional'));

    expect($result->accepted)->toBeTrue();
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://postal-tx:5000/')
        && $r->header('X-Server-API-Key')[0] === 'tx-kulcs');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'postal-mk')
        || ($r->header('X-Server-API-Key')[0] ?? null) === 'mk-kulcs');
});

it('a consent-köteles üzenet KIZÁRÓLAG a marketing példányra megy', function (string $channel) {
    (new PostalDriver)->send(pooled($channel));

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://postal-mk:5000/')
        && $r->header('X-Server-API-Key')[0] === 'mk-kulcs');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'postal-tx')
        || ($r->header('X-Server-API-Key')[0] ?? null) === 'tx-kulcs');
})->with(['marketing', 'campaign', 'journey']);

it('hiányzó marketing-példánynál a marketing üzenet NEM esik vissza a tranzakciósra', function () {
    config(['sending.postal.pools.marketing' => ['endpoint' => '', 'api_key' => '']]);
    // A régi, egy-végpontos beállítás sem lehet kerülőút.
    config(['sending.postal.endpoint' => 'http://postal-tx:5000', 'sending.postal.api_key' => 'tx-kulcs']);

    expect(fn () => (new PostalDriver)->send(pooled('campaign')))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('két poolhoz ugyanaz a példány nem konfigurálható', function () {
    config(['sending.postal.pools.marketing.endpoint' => 'http://postal-tx:5000']);

    expect(fn () => (new PostalDriver)->send(pooled('transactional')))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('két poolhoz ugyanaz a Return-Path domain nem konfigurálható', function () {
    config(['sending.postal.pools.marketing.return_path_domain' => 'rp-tx.ventmail.test']);

    expect(fn () => (new PostalDriver)->send(pooled('transactional')))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('a rossz osztályú IP-re foglalt üzenetet a driver visszautasítja', function () {
    $pool = IpPool::onPlatform(fn () => IpPool::create(['name' => 'tx', 'type' => 'shared', 'channel' => 'transactional']));
    $ip = IpAddress::create([
        'ip_pool_id' => $pool->id, 'address' => '192.0.2.10', 'hostname' => 'mta-tx1.ventmail.test',
        'warmup_state' => 'warm', 'status' => 'active', 'daily_limit' => 1000,
    ]);

    expect(fn () => (new PostalDriver)->send(pooled('campaign', ['ip_address_id' => $ip->id])))
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('a marketing pool kiszolgálja a campaign és journey csatornát, a tranzakciósat nem', function () {
    $marketing = new IpPool(['name' => 'mk', 'type' => 'shared', 'channel' => 'marketing']);
    $transactional = new IpPool(['name' => 'tx', 'type' => 'shared', 'channel' => 'transactional']);

    expect($marketing->servesChannel('campaign'))->toBeTrue();
    expect($marketing->servesChannel('journey'))->toBeTrue();
    expect($marketing->servesChannel('transactional'))->toBeFalse();
    expect($transactional->servesChannel('campaign'))->toBeFalse();
    expect($transactional->servesChannel('marketing'))->toBeFalse();
    expect($transactional->servesChannel('transactional'))->toBeTrue();
});

it('a Return-Path poolonként külön CNAME-re mutat', function () {
    $records = app(DnsRecordGenerator::class)->for(new Domain([
        'name' => 'notify.pelda.hu', 'dkim_selector' => 'vm1', 'dkim_public_key' => 'MIIB',
    ]));

    expect($records['return_path_transactional'])->toMatchArray([
        'type' => 'CNAME', 'name' => 'psrp-tx.notify.pelda.hu', 'value' => 'rp-tx.ventmail.test',
    ]);
    expect($records['return_path_marketing'])->toMatchArray([
        'type' => 'CNAME', 'name' => 'psrp-mk.notify.pelda.hu', 'value' => 'rp-mk.ventmail.test',
    ]);
});

it('belső végpont csak egycímkés (docker-hálózati) hostnévvel lehet http', function () {
    config(['sending.postal.pools.transactional.endpoint' => 'http://postal.example.test']);

    expect(fn () => (new PostalDriver)->send(pooled('transactional')))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('a health-check a Postal webszerver valódi oldalát kérdezi (a webnek nincs /health végpontja)', function () {
    Http::swap(new Factory);
    Http::fake(['postal-tx:5000/login' => Http::response('<html>Postal</html>'), '*' => Http::response('', 404)]);

    expect((new PostalDriver)->health()->healthy)->toBeTrue();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'postal-mk'));
});

it('a Postal-hivatkozás mentésének hibája nem írja felül a sikeres átadást (nincs dupla kézbesítés)', function () {
    Http::swap(new Factory);
    Http::fake(['postal-tx:5000/*' => Http::response(['status' => 'success', 'data' => [
        'message_id' => 'tx-1', 'messages' => ['cimzett@example.test' => ['id' => 1, 'token' => 'tok']],
    ]])]);

    Schema::rename('postal_message_refs', 'postal_message_refs_off');

    try {
        $result = (new PostalDriver)->send(pooled('transactional'));
    } finally {
        Schema::rename('postal_message_refs_off', 'postal_message_refs');
    }

    expect($result->accepted)->toBeTrue();
});
