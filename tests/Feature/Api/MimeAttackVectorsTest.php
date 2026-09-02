<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A kimenő MIME-út támadási vektorai. A security-review `php -r`-rel igazolta mindkét
 | fő rést: a duplikált fejlécnél az UTOLSÓ győzött (a levélkliens az ELSŐT mutatja), és a
 | `strpos(...) ?: LIMIT` a 0-t is falsy-nak vette.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send']);
});

function mimePost(string $raw, array $fields = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'm-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', array_merge([
        'message' => UploadedFile::fake()->createWithContent('message.mime', $raw),
    ], $fields));
}

function mime(array $headers, string $body = 'Szia'): string
{
    return implode("\r\n", [...$headers, '', $body, '']);
}

it('duplikált From fejléccel nem lehet feladót hamisítani', function () {
    // A levélkliens az ELSŐ From-ot mutatja, a gate az UTOLSÓT látta: így idegen domain
    // jelent meg a saját DKIM-ünkkel aláírva.
    mimePost(mime([
        'From: ceo@bank.example',
        'From: no-reply@pelda.hu',
        'To: cimzett@example.test',
        'Subject: Hamisítás',
    ]))->assertStatus(400)->assertJsonPath('error.code', 'MIME_DUPLICATE_HEADER');

    expect(Message::count())->toBe(0);
});

it('duplikált To/Subject sem megengedett', function () {
    mimePost(mime([
        'From: no-reply@pelda.hu',
        'To: a@example.test',
        'To: b@example.test',
        'Subject: x',
    ]))->assertStatus(400)->assertJsonPath('error.code', 'MIME_DUPLICATE_HEADER');
});

it('szeparátor nélküli MIME elutasítva', function () {
    mimePost('From: no-reply@pelda.hu')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'MIME_MALFORMED');
});

it('üres sorral kezdődő MIME elutasítva', function () {
    // A `strpos(...) ?: LIMIT` miatt ilyenkor a TELJES törzs fejlécként parse-olódott:
    // a törzsben idézett `To:` sorok boríték-címzettekké léptek elő.
    mimePost("\r\n\r\nFrom: no-reply@pelda.hu\r\nTo: aldozat@example.test")
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'MIME_MALFORMED');
});

it('a Bcc fejléc nem csempészhet be címzettet a kapuk mellett', function () {
    Suppression::create(['email' => 'tiltott@example.test', 'reason' => 'complaint']);

    $response = mimePost(mime([
        'From: no-reply@pelda.hu',
        'To: cimzett@example.test',
        'Bcc: tiltott@example.test',
        'Subject: x',
    ]));

    // Vagy elutasítjuk a Bcc-t, vagy átvisszük a kapukon — a CSENDES eldobás nem opció.
    expect($response->status())->toBeIn([400, 422]);
    expect($response->json('error.code'))->toBeIn(['MIME_BCC_NOT_SUPPORTED', 'SUPPRESSED']);
});

it('a beadott nyers MIME bájtra visszaolvasható', function () {
    // A törzs korábban a request végén megszűnt: a végpont 202-t adott, a levélnek
    // pedig nem volt tartalma — az éles driver üres levelet küldött volna.
    $raw = mime(['From: no-reply@pelda.hu', 'To: cimzett@example.test', 'Subject: Megőrizve'], 'Törzs-tartalom');

    mimePost($raw)->assertAccepted();

    $message = Message::firstOrFail();
    $stored = RawMimeMessage::query()->where('message_uuid', $message->uuid)->firstOrFail();

    expect($stored->raw)->toBe($raw);
    expect($stored->size)->toBe(strlen($raw));
});

it('a túl nagy MIME strukturált hibát ad', function () {
    config(['sending.max_mime_bytes' => 1024]);

    mimePost(mime(['From: no-reply@pelda.hu', 'To: c@example.test'], str_repeat('x', 4096)))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'MIME_TOO_LARGE');
});

it('a MIME-úton is érvényesül a teszt-kulcs', function () {
    ['plain' => $testKey] = ApiKey::issue('Teszt', ['messages:send'], testmode: true);

    test()->withHeaders([
        'Authorization' => 'Bearer '.$testKey,
        'Idempotency-Key' => 'mt-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('m.mime', mime([
            'From: no-reply@pelda.hu', 'To: c@example.test',
        ])),
    ])->assertAccepted();

    expect(Message::firstOrFail()->testmode)->toBeTrue();
});

it('a MIME-úton a nem támogatott vezérlő nem néma', function () {
    mimePost(mime(['From: no-reply@pelda.hu', 'To: c@example.test']), ['o:deliverytime' => now()->addHour()->toRfc2822String()])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'UNSUPPORTED_MIME_OPTION');
});
