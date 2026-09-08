<?php

declare(strict_types=1);

use App\Modules\Consent\Models\Consent;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Drivers\FakeDriver;
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

/*
 | A LEIRATKOZÓ FEJLÉC A NYERS MIME ÚTON (A6 · 3. security-kör, BL-B).
 |
 | A nyers MIME EGY bájtsor, amit MINDEN címzett ugyanúgy kap meg. A `MimeParser` viszont
 | korábban csak az `X-*` fejléceket adta tovább, tehát a `List-Unsubscribe` a modell és így
 | MINDEN KAPU elől eltűnt — miközben a bájtokkal kiment.
 */

it('a nyers MIME-ban a leiratkozó fejléc ELUTASÍTÁS', function () {
    // Az ügyfél fejléce ellenőrizetlenül, a MI DKIM-aláírásunkkal ment volna ki. Egy idegen
    // tenant elkapott tokenjével minden one-click egy IDEGEN workspace visszavonhatatlan
    // suppression-listájára írt — és itt még százalék-kódolás sem kellett hozzá, mert a
    // kapu ezt a fejlécet nem is látta.
    mimePost(mime([
        'From: no-reply@pelda.hu',
        'To: cimzett@example.test',
        'Subject: Hírlevél',
        'List-Unsubscribe: <https://idegen.example/u/ELKAPOTT-TOKEN>',
    ]))->assertStatus(400)->assertJsonPath('error.code', 'UNSUPPORTED_MIME_HEADER');

    expect(Message::count())->toBe(0);
});

it('consent-köteles csatornán a nyers MIME EGY címzettre küldhető', function () {
    // Egy közös bájtsorba csak EGY leiratkozó token fér: két címzettnél a második
    // leiratkozása az elsőt vinné a suppression-listára. Tudatos Mailgun-paritás-szűkítés —
    // a tömeges marketing útja a strukturált végpont.
    // A marketing csatornához a KULCS is jogosult kell legyen (`AuthorizeChannel`).
    ['plain' => $this->plain] = ApiKey::issue(
        'CI-marketing',
        ['messages:send', 'messages:send:marketing'],
        false,
        ['transactional', 'marketing'],
    );

    mimePost(mime([
        'From: no-reply@pelda.hu',
        'To: egy@example.test',
        'Subject: Hírlevél',
    ]), ['to' => 'egy@example.test,ketto@example.test', 'o:channel' => 'marketing'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'MIME_BULK_MARKETING');

    expect(Message::count())->toBe(0);
});

it('a tranzakciós nyers MIME viszont több címzettre is mehet', function () {
    // A szűkítés a consent-köteles csatornára szól: tranzakciós levélbe nem is teszünk
    // leiratkozó fejlécet, tehát nincs mit címzettenként átírni.
    mimePost(mime([
        'From: no-reply@pelda.hu',
        'To: egy@example.test',
        'Subject: Számla',
    ]), ['to' => 'egy@example.test,ketto@example.test'])->assertAccepted();
});

it('a nyers MIME-os marketing levélben BENNE VAN a leiratkozó fejléc', function () {
    // Ez volt a másik fele a hibának: a `created` hook beírta a fejlécet a
    // `messages.headers`-be, az `UnsubscribeHeaderGate` erre azt mondta, PASS — a kimenő
    // BÁJTOKBAN viszont nem volt `List-Unsubscribe`. Vagyis minden nyers MIME-os marketing
    // küldés RFC 8058-ra nem megfelelő volt (a Gmail és a Yahoo elutasítja), mind a tíz
    // kapu zöldje mellett. Ugyanaz a „a kapu engedte, a levélbe nem jutott ki" hibaosztály,
    // amit ez a packet a strukturált úton kétszer javított.
    Consent::create([
        'email' => 'egy@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
    ]);

    ['plain' => $this->plain] = ApiKey::issue(
        'CI-marketing',
        ['messages:send', 'messages:send:marketing'],
        false,
        ['transactional', 'marketing'],
    );

    FakeDriver::reset();

    mimePost(mime([
        'From: no-reply@pelda.hu',
        'To: egy@example.test',
        'Subject: Hírlevél',
    ]), ['o:channel' => 'marketing'])->assertAccepted();

    $mime = (string) (FakeDriver::$sent[0]['bodies']['egy@example.test']['mime'] ?? '');

    expect($mime)->toContain('List-Unsubscribe:');
    expect($mime)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click');

    // A fejléc a levél ELEJÉN van, tehát az aláírás (ha van kulcs) is fedi.
    expect(strpos($mime, 'List-Unsubscribe:'))->toBeLessThan((int) strpos($mime, 'Subject:'));
});
