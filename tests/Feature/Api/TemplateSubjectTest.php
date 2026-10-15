<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Drivers\PostalDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\SubjectSanitizer;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | FO1 — a tárolt sablonverzió saját TÁRGYAT kap (nullable `subject`). Ha a kérés nem hoz
 | `subject`-et, a sablon tárgya megy, a törzsével azonos `{{ var }}` szemantikával; a
 | tárgy sima szöveg (nincs HTML-escape), a CR/LF kiszűrve. A kérés tárgya MINDIG nyer.
 */

beforeEach(function () {
    FakeDriver::reset();

    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send', 'templates:write', 'templates:read']);
});

function subjectTemplate(?string $subject, string $name = 'order'): void
{
    $template = StoredTemplate::create(['name' => $name]);
    StoredTemplateVersion::create([
        'stored_template_id' => $template->getKey(), 'version' => 1,
        'body' => '<p>Szia {{ name }}</p>', 'subject' => $subject, 'is_active' => true,
    ]);
}

function sendTemplated(array $extra = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'k-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', array_merge([
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'template' => 'order',
    ], $extra));
}

function sentSubject(string $to = 'cimzett@example.test'): ?string
{
    return FakeDriver::$sent[0]['bodies'][$to]['subject'] ?? null;
}

it('a sablon tárgya megy, ha a kérés nem hoz tárgyat — a változók behelyettesítve', function () {
    subjectTemplate('Rendelés {{ order_no }} megérkezett');

    sendTemplated(['t:variables' => json_encode(['order_no' => 'A-17', 'name' => 'Anna'])])->assertAccepted();

    expect(sentSubject())->toBe('Rendelés A-17 megérkezett');
});

it('a kérés tárgya MINDIG nyer a sablon tárgyával szemben', function () {
    subjectTemplate('Sablon tárgy {{ order_no }}');

    sendTemplated(['subject' => 'Saját tárgy', 't:variables' => json_encode(['order_no' => '1'])])->assertAccepted();

    expect(sentSubject())->toBeNull(); // nincs sablon-tárgy renderelés: a message.subject megy
    expect(Message::query()->firstOrFail()->subject)->toBe('Saját tárgy');
});

it('a tárgy sima szöveg: nincs HTML-escape, és a CR/LF kiszűrve', function () {
    subjectTemplate('{{ who }} · Q&A');

    sendTemplated(['t:variables' => json_encode(['who' => "Tom & <Jerry>\r\nBcc: x@evil.test"])])->assertAccepted();

    $subject = sentSubject();

    expect($subject)->not->toContain("\n")->not->toContain("\r")
        ->and($subject)->toContain('Tom & <Jerry>')->toContain('Q&A')->not->toContain('&amp;');
});

it('a hiányzó változó fallback a tárgyban is, a `v:*` is behelyettesít', function () {
    subjectTemplate('Szia {{ nev || Vásárló }} #{{ ref }}');

    sendTemplated(['v:ref' => '42'])->assertAccepted();

    expect(sentSubject())->toBe('Szia Vásárló #42');
});

it('tárgy nélküli sablon és tárgy nélküli kérés: nincs sablon-tárgy, a régi viselkedés marad', function () {
    subjectTemplate(null);

    sendTemplated()->assertAccepted();

    expect(sentSubject())->toBeNull()
        ->and(Message::query()->firstOrFail()->subject)->toBeNull();
});

it('a címzettenkénti változók a batch minden címzettjének a SAJÁT tárgyát adják', function () {
    subjectTemplate('Szia {{ name }}!');

    sendTemplated([
        'to' => 'a@example.test,b@example.test',
        'recipient-variables' => json_encode([
            'a@example.test' => ['name' => 'Aladár'],
            'b@example.test' => ['name' => 'Béla'],
        ]),
    ])->assertAccepted();

    expect(sentSubject('a@example.test'))->toBe('Szia Aladár!')
        ->and(sentSubject('b@example.test'))->toBe('Szia Béla!');
});

it('a tárgy-renderelés egyetlen passos: a behelyettesített érték nem értékelődik újra', function () {
    subjectTemplate('Szia {{ name }}');

    sendTemplated(['t:variables' => json_encode(['name' => '{{ secret }}', 'secret' => 'TITOK'])])->assertAccepted();

    expect(sentSubject())->toBe('Szia {{ secret }}');
});

it('az idempotens újrahívás ugyanazt a levelet adja, és nem küld kétszer', function () {
    subjectTemplate('Rendelés {{ order_no }}');
    $key = 'k-fixed-subject';
    $headers = ['Authorization' => 'Bearer '.$this->plain, 'Idempotency-Key' => $key, 'Accept' => 'application/json'];
    $body = ['from' => 'no-reply@pelda.hu', 'to' => 'cimzett@example.test', 'template' => 'order', 't:variables' => json_encode(['order_no' => '9'])];

    $this->withHeaders($headers)->postJson('/v1/messages', $body)->assertAccepted();
    $this->withHeaders($headers)->postJson('/v1/messages', $body)->assertAccepted();

    expect(FakeDriver::$sent)->toHaveCount(1);
});

it('a renderelt tárgy MINDEN vezérlő- és sortörő karaktertől mentes, és 255-re vágott (B1, N6)', function () {
    subjectTemplate('A {{ x }} B');

    $evil = "N\x01U\x07L\x0B\x0C\x1F\x7F\u{0085}\u{2028}\u{2029}\u{0080}\u{009F}\r\nBcc: x@evil.test";

    sendTemplated(['t:variables' => json_encode(['x' => $evil])])->assertAccepted();

    $subject = sentSubject();

    expect(preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]/u', $subject))->toBe(0)
        ->and($subject)->toStartWith('A N U L')->and($subject)->toContain('Bcc: x@evil.test')
        ->and($subject)->not->toContain('  ');

    // Hosszú változó: a messages.subject varchar(255) szintjén vág.
    FakeDriver::reset();
    sendTemplated(['t:variables' => json_encode(['x' => str_repeat('é', 400)])])->assertAccepted();
    expect(mb_strlen(sentSubject()))->toBeLessThanOrEqual(255);
});

it('a NUL a változóban már a bemeneti szűrőn elbukik (422), és a tisztító is szóközre cseréli (mélységi védelem)', function () {
    subjectTemplate('A {{ x }} B');

    sendTemplated(['t:variables' => json_encode(['x' => "n\x00ul"])])->assertStatus(422);

    expect(SubjectSanitizer::clean("a\x00b\x0Bc\x0Cd\u{0085}e\u{2028}f\u{2029}g"))->toBe('a b c d e f g');
});

it('a MIME Subject fejléc DKIM-aláírt úton is a renderelt, tisztított tárgy (S9)', function () {
    Domain::query()->where('name', 'pelda.hu')->firstOrFail()->forceFill([
        'dkim_selector' => 'vm202609',
        'dkim_private_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem')),
        'dkim_public_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem')),
    ])->save();
    subjectTemplate('Rendelés {{ no }}');

    sendTemplated(['t:variables' => json_encode(['no' => "A-1\r\nX: y"])])->assertAccepted();

    $mime = FakeDriver::$sent[0]['bodies']['cimzett@example.test']['mime'];

    // A fejléc RFC 2047 szerint kódolt lehet: a dekódolt érték számít.
    $headers = iconv_mime_decode_headers($mime, 0, 'UTF-8');

    expect($mime)->toContain('DKIM-Signature')
        ->and($headers['Subject'])->toBe('Rendelés A-1 X: y');
});

it('a Postal JSON subject a renderelt tárgy (S9)', function () {
    config([
        'sending.postal.endpoint' => '', 'sending.postal.api_key' => '',
        'sending.postal.pools' => [
            'transactional' => ['endpoint' => 'http://postal-tx:5000', 'api_key' => 'tx', 'return_path_prefix' => 'psrp-tx', 'return_path_domain' => 'rp-tx.ventmail.test'],
            'marketing' => ['endpoint' => 'http://postal-mk:5000', 'api_key' => 'mk', 'return_path_prefix' => 'psrp-mk', 'return_path_domain' => 'rp-mk.ventmail.test'],
        ],
    ]);
    $this->app->bind(SendingDriver::class, PostalDriver::class);
    Http::fake(['postal-tx:5000/*' => Http::response(['status' => 'success', 'data' => ['message_id' => 'm1', 'messages' => ['cimzett@example.test' => ['id' => 1, 'token' => 't']]]])]);
    subjectTemplate('Rendelés {{ no }}');

    sendTemplated(['t:variables' => json_encode(['no' => "42\u{2028}!"])])->assertAccepted();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/send/message') && $r['subject'] === 'Rendelés 42 !');
});

it('a /v1 új verzió (tárgy-mező nélkül) örökli az aktív verzió tárgyát (S8)', function () {
    subjectTemplate('Rendelés {{ no }}');

    test()->withHeaders(['Authorization' => 'Bearer '.test()->plain, 'Accept' => 'application/json'])
        ->postJson('/v1/templates/order/versions', ['template' => '<p>v2</p>', 'active' => true])->assertCreated();

    $active = StoredTemplate::query()->where('name', 'order')->firstOrFail()->activeVersion();

    expect((int) $active->version)->toBe(2)->and($active->body)->toBe('<p>v2</p>')->and($active->subject)->toBe('Rendelés {{ no }}');
});

it('tárgy nélküli aktív verzió után az új verzió is tárgy nélküli', function () {
    subjectTemplate(null);

    test()->withHeaders(['Authorization' => 'Bearer '.test()->plain, 'Accept' => 'application/json'])
        ->postJson('/v1/templates/order/versions', ['template' => '<p>v2</p>'])->assertCreated();

    expect(StoredTemplateVersion::query()->where('version', 2)->firstOrFail()->subject)->toBeNull();
});
