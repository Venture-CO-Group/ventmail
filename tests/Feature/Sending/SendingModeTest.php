<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Gate\GateDecision;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Sending\Testing\FakeConsentChecker;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
 | ÉLES INDULÁS — a küldési mód (deploy-ovh 3. pont, kemény szabály 3).
 |
 | Az éles gépen a `SENDING_LIVE` és a marketing-küldés ALAPBÓL ki van kapcsolva, a
 | seed-allowlist pedig mindenki mást elutasít. Ezeket csak a tulajdonos kapcsolja át.
 | A tesztkörnyezet a phpunit.xml-ben NYITJA ki a küldést — itt ezért minden teszt a saját
 | állapotát állítja be, és az alapértéket a config-fájlból olvassa vissza.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    app()->instance(ConsentChecker::class, new FakeConsentChecker(confirmed: true));

    RateLimiter::clear('sending:'.$this->workspace->id);
});

function modeSend(array $overrides = []): GateDecision
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => 'seed@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ], $overrides))->decision;
}

/** A config-fájl VALÓDI alapértékei, env nélkül — az éles gép első indulása. */
function productionDefaults(): void
{
    $original = $_ENV + $_SERVER;

    foreach (['SENDING_LIVE', 'SENDING_MARKETING_ENABLED', 'SEND_ALLOWLIST', 'SEND_ALLOWLIST_ENFORCED'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        $config = require base_path('config/sending.php');
    } finally {
        foreach (['SENDING_LIVE', 'SENDING_MARKETING_ENABLED', 'SEND_ALLOWLIST', 'SEND_ALLOWLIST_ENFORCED'] as $key) {
            if (array_key_exists($key, $original)) {
                putenv($key.'='.$original[$key]);
                $_ENV[$key] = $_SERVER[$key] = $original[$key];
            }
        }
    }

    config()->set('sending.live', $config['live']);
    config()->set('sending.marketing_enabled', $config['marketing_enabled']);
    config()->set('sending.allowlist', $config['allowlist']);
    config()->set('sending.allowlist_enforced', $config['allowlist_enforced']);
}

function seedMode(array $allowlist): void
{
    config()->set('sending.live', false);
    config()->set('sending.marketing_enabled', false);
    config()->set('sending.allowlist', $allowlist);
    config()->set('sending.allowlist_enforced', true);
}

it('az alapértékek biztonságosak: nem éles, marketing ki, allowlist kötelező és üres', function () {
    productionDefaults();

    expect(config('sending.live'))->toBeFalse();
    expect(config('sending.marketing_enabled'))->toBeFalse();
    expect(config('sending.allowlist_enforced'))->toBeTrue();
    expect(config('sending.allowlist'))->toBe([]);

    $decision = modeSend();

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::SendingRestricted->value);
});

it('seed-módban az allowlistes címzett átmegy', function () {
    seedMode(['seed@example.test']);

    expect(modeSend()->allowed)->toBeTrue();
});

it('seed-módban az allowlisten kívüli címzett elutasítva', function () {
    seedMode(['seed@example.test']);

    $decision = modeSend(['to' => 'valaki@example.test']);

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::SendingRestricted->value);
});

it('egyetlen allowlisten kívüli címzett az EGÉSZ üzenetet megállítja', function () {
    seedMode(['seed@example.test']);

    $decision = modeSend(['to' => ['seed@example.test', 'idegen@example.test']]);

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::SendingRestricted->value);
});

it('az allowlist kis-nagybetű és szóköz független, a domain-bejegyzés pontos', function () {
    seedMode(['  Seed@Example.TEST ', '@srv1.mail-tester.test']);

    expect(modeSend(['to' => 'seed@example.test'])->allowed)->toBeTrue();
    expect(modeSend(['to' => 'test-abc@srv1.mail-tester.test'])->allowed)->toBeTrue();

    // A domain-bejegyzés NEM fedi az aldomaint és a hasonló végződést.
    expect(modeSend(['to' => 'x@evil-srv1.mail-tester.test'])->reason)->toBe(GateReason::SendingRestricted->value);
    expect(modeSend(['to' => 'x@a.srv1.mail-tester.test'])->reason)->toBe(GateReason::SendingRestricted->value);
});

it('nem élesben az allowlist akkor is érvényes, ha a kötelezősége ki van kapcsolva', function () {
    seedMode(['seed@example.test']);
    config()->set('sending.allowlist_enforced', false);

    expect(modeSend(['to' => 'valaki@example.test'])->reason)->toBe(GateReason::SendingRestricted->value);
});

it('élesben, bekapcsolt allowlisttel továbbra is csak az allowlist mehet', function () {
    seedMode(['seed@example.test']);
    config()->set('sending.live', true);

    expect(modeSend(['to' => 'valaki@example.test'])->reason)->toBe(GateReason::SendingRestricted->value);
    expect(modeSend()->allowed)->toBeTrue();
});

it('élesben, kikapcsolt allowlisttel bárki kaphat tranzakciós levelet', function () {
    seedMode([]);
    config()->set('sending.live', true);
    config()->set('sending.allowlist_enforced', false);

    expect(modeSend(['to' => 'valaki@example.test'])->allowed)->toBeTrue();
});

it('kikapcsolt marketingnél minden consent-köteles csatorna tiltott — allowlistes címre is', function (string $channel) {
    seedMode(['seed@example.test']);

    $decision = modeSend(['channel' => $channel]);

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::ChannelDisabled->value);
})->with(['marketing', 'campaign', 'journey']);

it('bekapcsolt marketingnél a küldési mód nem állja útját a marketing levélnek', function () {
    seedMode(['seed@example.test']);
    config()->set('sending.marketing_enabled', true);

    $decision = modeSend(['channel' => 'marketing']);

    expect($decision->reason)->not->toBe(GateReason::ChannelDisabled->value);
    expect($decision->reason)->not->toBe(GateReason::SendingRestricted->value);
});

it('a küldés pillanatában újra ellenőriz: a közben szűkített allowlist megállítja a sorban álló levelet', function () {
    seedMode(['seed@example.test']);

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => 'seed@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
        'o:deliverytime' => now()->addMinutes(5)->toRfc2822String(),
    ]);

    expect($result->decision->allowed)->toBeTrue();

    $driver = new class implements SendingDriver
    {
        public int $calls = 0;

        public function send(Message $message): DispatchResult
        {
            $this->calls++;

            return DispatchResult::accepted('x');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities('spy', true, true, false, false);
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(true, 'ok');
        }
    };
    app()->instance(SendingDriver::class, $driver);

    // A tulajdonos közben kiveszi a címet az allowlistből.
    config()->set('sending.allowlist', []);

    $message = $result->message;
    $message->forceFill(['status' => 'accepted', 'gate_decided_at' => now()])->save();

    app(MessageDispatcher::class)->dispatch($message->fresh());

    expect($driver->calls)->toBe(0);
    expect($message->fresh()->status)->toBe('rejected');
    expect($message->fresh()->reason)->toBe(GateReason::SendingRestricted->value);
});
