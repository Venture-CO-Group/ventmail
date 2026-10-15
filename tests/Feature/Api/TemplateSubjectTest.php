<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
