<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | NEM VISSZHANGZÓ SABLONVÁLTOZÓK — `t:variables` (deploy-ovh, security-review B1).
 |
 | A `v:*` változók Mailgun-paritás szerint VISSZAJÖNNEK: az üzenet során, minden
 | eseményben, a webhookban és a `/v1/events`-ben. Egy jelszó-visszaállító link, magic link
 | vagy OTP-kód ott fiók-átvételi eszköz lenne bárkinek, aki eseményt olvashat. A
 | `t:variables` CSAK a rendereléshez kell: titkosítva várja a küldést, a küldés után
 | törlődik, és semmilyen naplóba, eseménybe vagy válaszba nem kerül.
 */

const SECRET_LINK = 'https://sso.example.test/reset?token=TITKOS-TOKEN-123';

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('sso', ['messages:send', 'templates:write', 'events:read']);

    $this->withHeaders(rvHeaders())->postJson('/v1/templates', [
        'name' => 'sso-password_reset-hu', 'template' => '<p>Új jelszó: <a href="{{ url }}">link</a> · {{ app }}</p>',
    ])->assertCreated();
});

function rvHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'k-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ];
}

function rvSend(array $extra = []): TestResponse
{
    return test()->withHeaders(rvHeaders())->postJson('/v1/messages', array_merge([
        'from' => 'noreply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Jelszó visszaállítása',
        'template' => 'sso-password_reset-hu',
        'v:app' => 'ventSSO',
        't:variables' => json_encode(['url' => SECRET_LINK]),
    ], $extra));
}

it('a t:variables behelyettesítődik a kiküldött levélbe', function () {
    rvSend()->assertAccepted();

    $html = FakeDriver::$sent[0]['bodies']['cimzett@example.test']['html'];

    expect($html)->toContain(htmlspecialchars(SECRET_LINK, ENT_QUOTES))->toContain('ventSSO');
});

it('a t:variables SEHOL nem marad meg olvashatóan: üzenet, esemény, napló, API', function () {
    $response = rvSend()->assertAccepted();

    expect($response->getContent())->not->toContain('TITKOS-TOKEN');

    $message = Message::query()->firstOrFail();
    expect(json_encode($message->variables))->not->toContain('TITKOS-TOKEN')
        ->and($message->toArray())->not->toHaveKey('render_variables');

    // Küldés után a titkosított másolat is törlődik.
    expect(DB::table('messages')->where('id', $message->id)->value('render_variables'))->toBeNull();

    foreach (MessageEvent::query()->get() as $event) {
        expect(json_encode($event->toArray()))->not->toContain('TITKOS-TOKEN');
    }

    $this->withHeaders(rvHeaders())->getJson('/v1/events')->assertOk()
        ->assertDontSee('TITKOS-TOKEN');
});

it('várakozás közben (ütemezett levél) csak titkosítva tárolódik', function () {
    rvSend(['o:deliverytime' => now()->addHour()->toRfc2822String()])->assertAccepted();

    $raw = (string) DB::table('messages')->value('render_variables');

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain('TITKOS-TOKEN')
        ->and(Message::query()->firstOrFail()->render_variables)->toBe(['url' => SECRET_LINK]);
});

it('a t:variables a v:*-gal azonos kulcsot felülírja (a rendereléshez)', function () {
    rvSend(['v:url' => 'https://nem-ez.test'])->assertAccepted();

    expect(FakeDriver::$sent[0]['bodies']['cimzett@example.test']['html'])->toContain('TITKOS-TOKEN-123')
        ->not->toContain('nem-ez.test');
});

it('érvénytelen t:variables strukturált 400', function (mixed $value) {
    rvSend(['t:variables' => $value])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TEMPLATE_VARIABLES');
})->with([
    'nem JSON' => ['{nem json'],
    'nem objektum' => ['[1,2]'],
    'nem skalár érték' => [json_encode(['url' => ['beágyazott']])],
]);

it('az idempotencia-lenyomat a t:variables tartalmát is figyelembe veszi', function () {
    $headers = rvHeaders();

    $this->withHeaders($headers)->postJson('/v1/messages', [
        'from' => 'noreply@pelda.hu', 'to' => 'cimzett@example.test', 'subject' => 'x',
        'template' => 'sso-password_reset-hu', 't:variables' => json_encode(['url' => 'https://a.test']),
    ])->assertAccepted();

    $this->withHeaders($headers)->postJson('/v1/messages', [
        'from' => 'noreply@pelda.hu', 'to' => 'cimzett@example.test', 'subject' => 'x',
        'template' => 'sso-password_reset-hu', 't:variables' => json_encode(['url' => 'https://b.test']),
    ])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
});

it('a lenyomatokból (kérés, törzs) egy rövid kód sem fejthető vissza kulcs nélkül', function () {
    $this->withHeaders(rvHeaders())->postJson('/v1/templates', [
        'name' => 'sso-email_otp-hu', 'template' => '<p>Kódod: {{ code }}</p>',
    ])->assertCreated();

    rvSend(['template' => 'sso-email_otp-hu', 't:variables' => json_encode(['code' => '483920'])])->assertAccepted();

    $message = Message::query()->latest('id')->firstOrFail();
    $bodyHash = MessageEvent::query()->where('message_uuid', $message->uuid)->whereNotNull('payload->body_sha256')->value('payload')['body_sha256'] ?? null;

    // A támadó ismeri a (publikus) sablont és a többi mezőt: kulcs nélküli SHA-256 esetén
    // egy 10^6-os próbálkozás visszaadná a kódot. Kulcsolt lenyomattal egyik jelölt sem egyezik.
    $candidate = '<p>Kódod: 483920</p>';
    expect($bodyHash)->not->toBeNull()
        ->and($bodyHash)->not->toBe(hash('sha256', $candidate))
        ->and($message->request_fingerprint)->not->toContain('483920');

    $plain = hash('sha256', (string) json_encode(collect([
        'channel' => 'transactional', 'from_email' => 'noreply@pelda.hu', 'subject' => 'Jelszó visszaállítása',
        'render_variables' => ['code' => '483920'],
    ])->sortKeys()->all()));
    expect($message->request_fingerprint)->not->toBe($plain);
});
