<?php

declare(strict_types=1);

use App\Modules\Consent\Models\Consent;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Messages\Data\MailgunPayload;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\UnsubscribeHeaderPolicy;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A LEIRATKOZÓ FEJLÉC A MIÉNK — a HÁROM belépési pont ellenőrzése.
 |
 | Ez a fájl azért létezik, mert ugyanez a hiba ÖT egymást követő review-körben ötször nyílt
 | ki, és minden alkalommal máshol:
 |
 |  1. a batch minden levele az ELSŐ címzett tokenjét vitte;
 |  2. a javítás az URL-előtag elcsúszásán nyílt vissza (config-változás, támadó nélkül);
 |  3. egy MÁS BETŰZÉSŰ ügyfél-kulcs nyitotta ki (a `headers` jsonb, a kulcssorrend a
 |     Postgresé, a kód pedig az elsőn `break`-elt);
 |  4. egy SZÁZALÉK-KÓDOLT token (a kapu a nyers szöveget regexelte, a router a dekódolt
 |     útvonalon illeszt);
 |  5. a bemeneti zár VEZETŐ SZÓKÖZZEL (`h: List-Unsubscribe`), mert a zár nem trimmelt, a
 |     modell kanonizálása viszont igen — és a fejléc kanonikus néven landolt a levélben.
 |
 | A tanulság: EGY zár, EGY normalizálás, MINDEN úton. A zár a
 | `UnsubscribeHeaderPolicy::isManaged()`, és mindhárom belépési pont ezt hívja.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function mimeSubmit(string $raw, array $fields = []): TestResponse
{
    ['plain' => $plain] = ApiKey::issue('CI', ['messages:send']);

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$plain,
        'Idempotency-Key' => 'u-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', array_merge([
        'message' => UploadedFile::fake()->createWithContent('m.mime', $raw),
    ], $fields));
}

it('a NORMALIZÁLÁS egységes: whitespace, betűzés és részleges név is a MIÉNK', function () {
    // Az ötödik variáns: a zár nem trimmelt, a `Message::canonicalHeaderName()` viszont
    // trimmel. Ez a normalizálási aszimmetria volt a rés — egy szóköz kinyitotta.
    foreach ([
        'List-Unsubscribe',
        'list-unsubscribe',
        'LIST-UNSUBSCRIBE',
        ' List-Unsubscribe',
        "\tList-Unsubscribe",
        'List-Unsubscribe ',
        'List-Unsubscribe-Post',
        'list-unsubscribe-post',
    ] as $name) {
        expect(UnsubscribeHeaderPolicy::isManaged($name))->toBeTrue($name);
    }

    // …és ami NEM a miénk, az nem is lesz az.
    foreach (['X-List-Unsubscribe', 'Reply-To', 'X-Kampany', 'Unsubscribe'] as $name) {
        expect(UnsubscribeHeaderPolicy::isManaged($name))->toBeFalse($name);
    }
});

it('a STRUKTURÁLT úton a `h:` leiratkozó fejléc elutasítás — whitespace-szel is', function () {
    // Mérve: a `h: List-Unsubscribe` (vezető szóköz) korábban 202-t kapott, és a fejléc
    // KANONIKUS néven került a levélbe — a MI DKIM-aláírásunkkal hitelesítve.
    foreach ([
        'h:List-Unsubscribe',
        'h: List-Unsubscribe',
        "h:\tList-Unsubscribe",
        'h:  list-unsubscribe',
        'h:LIST-UNSUBSCRIBE',
        'h:List-Unsubscribe-Post',
    ] as $key) {
        expect(fn () => MailgunPayload::fromRequest([
            'from' => 'no-reply@pelda.hu',
            'to' => 'anna@example.test',
            'subject' => 'Szia',
            'text' => 'Szia',
            $key => '<https://tamado.example/u/AKARMI>',
        ]))->toThrow(InvalidArgumentException::class, 'VALIDATION_ERROR', $key);
    }
});

it('a TRANZAKCIÓS levélből is kimarad a leiratkozó fejléc', function () {
    // A `headersFor()` tranzakciós ága korábban KORÁN visszatért, tehát nem törölt semmit:
    // épp a tranzakciós levélben jutott ki az átcsúszott ügyfél-fejléc.
    $message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['anna@example.test'],
        'subject' => 'Számla',
        // A modellen keresztül (a bemeneti zárat megkerülve) beírt fejléc.
        'headers' => ['List-Unsubscribe' => '<https://tamado.example/u/AKARMI>'],
    ]);

    $outgoing = $message->fresh()->headersFor('anna@example.test');

    expect(UnsubscribeHeaderPolicy::managedAmong($outgoing))->toBe([]);
});

it('a TÁROLT fejléc PLACEHOLDER, nem élő token', function () {
    // A `messages` a naplózás főtáblája: egy tárolt, valódi tokennel bárki, aki naplót
    // olvashat, le tudná iratkoztatni a `recipients[0]`-t.
    Consent::create([
        'email' => 'anna@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
    ]);

    $message = Message::create([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['anna@example.test', 'bela@example.test'],
        'subject' => 'Hírlevél',
        'headers' => [],
    ]);

    expect($message->fresh()->headers['List-Unsubscribe'])
        ->toContain(Message::UNSUBSCRIBE_PLACEHOLDER);

    // …a KIMENŐ változat viszont valódi, címzettenkénti token.
    expect($message->fresh()->headersFor('bela@example.test')['List-Unsubscribe'])
        ->not->toContain(Message::UNSUBSCRIBE_PLACEHOLDER);
});

it('a PLACEHOLDER beváltása nem tesz semmit', function () {
    // Ha valaha mégis kimenne, a végpont 200-at ad és nem ír — nincs se hatás, se orákulum.
    $this->post('/u/'.Message::UNSUBSCRIBE_PLACEHOLDER)->assertOk();

    expect(Suppression::query()->count())->toBe(0);
});

it('a NYERS MIME úton a leiratkozó fejléc elutasítás', function () {
    mimeSubmit(implode("\r\n", [
        'From: no-reply@pelda.hu',
        'To: anna@example.test',
        'Subject: Szia',
        'List-Unsubscribe: <https://tamado.example/u/AKARMI>',
        '', 'Szia', '',
    ]))->assertStatus(400)->assertJsonPath('error.code', 'UNSUPPORTED_MIME_HEADER');

    expect(Message::count())->toBe(0);
});

it('a nyers MIME-os marketing levél aláírása FEDI a leiratkozó fejlécet', function () {
    // RFC 8058 §3: a `List-Unsubscribe` és a `-Post` fejlécet az aláírásnak FEDNIE kell, és
    // szerepelnie kell a `h=` tagban — enélkül a fogadó „SHOULD NOT offer a one-click
    // unsubscribe". A fejléc korábban ott volt a bájtokban, a `h=` viszont nem sorolta:
    // a megfelelés a nyers MIME úton így NEM teljesült, miközben a kapu zöldet adott.
    // A `beforeEach` domainjét EGÉSZÍTJÜK KI kulccsal: az `(workspace_id, name)` egyedi,
    // tehát a törlés-újralétrehozás itt csak ütközést hozna.
    $domain = Domain::query()->where('name', 'pelda.hu')->firstOrFail();
    $domain->dkim_selector = 'vm202609';
    $domain->dkim_active_selector = 'vm202609';
    $domain->dkim_private_key = (string) file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem'));
    $domain->save();

    Consent::create([
        'email' => 'anna@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
    ]);

    ['plain' => $plain] = ApiKey::issue(
        'CI-marketing',
        ['messages:send', 'messages:send:marketing'],
        false,
        ['transactional', 'marketing'],
    );

    FakeDriver::reset();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$plain,
        'Idempotency-Key' => 'u-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('m.mime', implode("\r\n", [
            'From: no-reply@pelda.hu',
            'To: anna@example.test',
            'Subject: Hírlevél',
            '', 'Szia', '',
        ])),
        'o:channel' => 'marketing',
    ])->assertAccepted();

    $mime = (string) (FakeDriver::$sent[0]['bodies']['anna@example.test']['mime'] ?? '');

    expect($mime)->toContain('DKIM-Signature:');
    expect($mime)->toContain('List-Unsubscribe:');

    // A `h=` lista NEVESÍTI a leiratkozó fejléceket. A neveket kettőspont VÁLASZTJA el, az
    // utolsó után nincs — ezért a listát felbontjuk, nem részsztringet keresünk.
    preg_match('/h=([^;]+);/', $mime, $h);

    $signedHeaders = array_map(
        static fn (string $name) => strtolower(trim($name)),
        explode(':', (string) ($h[1] ?? '')),
    );

    expect($signedHeaders)->toContain('list-unsubscribe');
    expect($signedHeaders)->toContain('list-unsubscribe-post');
    expect($domain->fresh()->signingSelector())->toBe('vm202609');
});
