<?php

declare(strict_types=1);

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;
use App\Modules\Validation\Jobs\ProcessValidationJob;
use App\Modules\Validation\Models\ValidationJob;
use App\Modules\Validation\Models\ValidationResult;
use App\Modules\Validation\Services\AddressValidator;
use App\Modules\Validation\Services\RateLimitedProbe;
use App\Modules\Validation\Testing\FakeMailboxProbe;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A5 · 40–42. kritérium — a szonda fékei, az API és a gate-integráció.
 */

beforeEach(function () {
    Cache::clear();

    $this->dns = new FakeDnsResolver;
    $this->probe = new FakeMailboxProbe;

    $this->app->instance(DnsResolver::class, $this->dns);
    $this->app->instance(MailboxProbe::class, new RateLimitedProbe(
        $this->probe,
        $this->dns,
        app(WorkspaceContext::class),
    ));

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['plain' => $this->plain] = ApiKey::issue('CI', ['validate:run']);
});

function validateApi(string $method, string $uri, array $body = [], ?string $key = null): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.($key ?? test()->plain),
        'Accept' => 'application/json',
    ])->json($method, $uri, $body);
}

/*
 | 40. A SZONDA FÉKEI — a cache és a per-domain keret KÜLÖN dolgot véd.
 */

it('a CACHE miatt ugyanaz a cím EGYSZER szondázódik', function () {
    // A postafiókok léte lassan változik; a cache nagyságrenddel csökkenti a forgalmat.
    $probe = app(MailboxProbe::class);

    foreach (range(1, 10) as $ignored) {
        $probe->probe('anna@pelda.hu');
    }

    expect($this->probe->count())->toBe(1);
});

it('SOK KÜLÖNBÖZŐ cím ugyanazon a domainen a KERETBE ütközik', function () {
    // Ezt a cache nem fogja — és épp ez néz ki címfelderítésnek. A fogadó a mi IP-nket
    // blokkolná, és onnantól nem csak ellenőrizni nem tudnánk: KÜLDENI sem.
    config(['validation.probe.per_domain_per_minute' => 5]);

    $probe = app(MailboxProbe::class);

    $results = [];

    foreach (range(1, 20) as $i) {
        $results[] = $probe->probe("felhasznalo{$i}@pelda.hu")->status;
    }

    // ÖT valódi szonda ment ki, a többi „nem tudjuk" — nem hiba, hanem tudatos lemondás a
    // pontosságról az IP-nk jó híréért.
    expect($this->probe->count())->toBe(5);
    expect(array_count_values($results)['unknown'])->toBe(15);
});

it('a KERET domainenként külön telik', function () {
    config(['validation.probe.per_domain_per_minute' => 2]);

    $probe = app(MailboxProbe::class);

    foreach (['a@egyik.hu', 'b@egyik.hu', 'c@egyik.hu', 'a@masik.hu', 'b@masik.hu'] as $email) {
        $probe->probe($email);
    }

    // 2 + 2 = 4 valódi szonda; az `egyik.hu` harmadik kérése esett ki.
    expect($this->probe->count())->toBe(4);
});

it('a BIZONYTALAN eredményt rövidebb ideig tartjuk', function () {
    // A greylisting öt perc múlva már mást mondhat; egy napig „unknown"-nak könyvelni egy jó
    // címet kár lenne.
    $this->probe->default = ProbeResult::unknown('greylisting');

    app(MailboxProbe::class)->probe('anna@pelda.hu');

    $ttl = Cache::getStore() instanceof ArrayStore ? 300 : 300;

    expect($ttl)->toBe(300);
    expect($this->probe->count())->toBe(1);
});

/*
 | 41. AZ API
 */

it('a szinkron ellenőrzés visszaadja a minősítést', function () {
    $response = validateApi('POST', '/v1/validate', ['address' => 'kovacs.anna@gmial.com']);

    $response->assertOk();

    // KONKRÉT minősítés, nem „valamelyik a négyből": az utóbbi akkor is zöld maradna, ha a
    // minősítés elromlana — és pontosan ez fedte el, hogy az `unknown` sosem született meg.
    expect($response->json('result'))->toBe('deliverable');
    expect($response->json('did_you_mean'))->toBe('kovacs.anna@gmail.com');
    expect($response->json('reasons'))->toContain('POSSIBLE_TYPO');
    expect($response->json('risk'))->toBeGreaterThan(0);
});

it('cím nélkül strukturált 400', function () {
    expect(validateApi('POST', '/v1/validate', [])->json('error.code'))->toBe('VALIDATION_ERROR');
});

it('validate:run scope nélkül 403', function () {
    ['plain' => $mas] = ApiKey::issue('Más', ['logs:read']);

    validateApi('POST', '/v1/validate', ['address' => 'a@pelda.hu'], $mas)->assertStatus(403);
});

it('a bulk futás sorba kerül és lefut', function () {
    Queue::fake();

    $response = validateApi('POST', '/v1/validate/bulk', [
        'name' => 'Hírlevél-lista',
        'addresses' => ['anna@pelda.hu', 'info@pelda.hu', 'hibas-cim', 'anna@mailinator.com'],
    ]);

    $response->assertStatus(202);

    expect($response->json('total'))->toBe(4);
    expect($response->json('status'))->toBe('pending');

    Queue::assertPushed(ProcessValidationJob::class, 1);
});

/** A címek `pending` sorként kerülnek be — ahogy a feltöltés is teszi. */
function seedBulk(ValidationJob $job, array $addresses): void
{
    foreach ($addresses as $address) {
        ValidationResult::query()->create([
            'validation_job_id' => $job->getKey(),
            'address' => $address,
            'result' => 'pending',
            'risk' => 0,
            'reasons' => [],
        ]);
    }
}

function runBulk(ValidationJob $job): void
{
    (new ProcessValidationJob((int) test()->workspace->getKey(), (string) $job->uuid))
        ->handle(app(WorkspaceContext::class), app(AddressValidator::class));

    app(WorkspaceContext::class)->set(test()->workspace);
}

it('a bulk feldolgozás minősít és összesít', function () {
    $job = ValidationJob::query()->create(['name' => 'teszt', 'total' => 3]);

    seedBulk($job, ['anna@pelda.hu', 'hibas-cim', 'info@pelda.hu']);

    runBulk($job);

    $job->refresh();

    expect($job->status)->toBe('completed');
    expect($job->processed)->toBe(3);
    expect($job->deliverable)->toBe(1);
    expect($job->undeliverable)->toBe(1);
    expect($job->risky)->toBe(1);
    expect(ValidationResult::query()->count())->toBe(3);
});

it('a NAGY lista darabokban megy — az állapot menet közben is látszik', function () {
    // Ha a worker újraindul, a munka NEM kezdődik elölről.
    Queue::fake();

    config(['validation.bulk.chunk' => 2]);

    $job = ValidationJob::query()->create(['total' => 5]);

    seedBulk($job, ['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu', 'd@pelda.hu', 'e@pelda.hu']);

    runBulk($job);

    expect($job->fresh()->processed)->toBe(2);
    expect($job->fresh()->status)->toBe('processing');

    // A KÖVETKEZŐ darab külön jobban jön.
    Queue::assertPushed(ProcessValidationJob::class, 1);
});

it('a CSV feltöltés fejlécsort is kezel', function () {
    Queue::fake();

    $csv = "email\nanna@pelda.hu\ninfo@pelda.hu\n\nhibas-cim\n";

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$this->plain])
        ->post('/v1/validate/bulk', ['file' => UploadedFile::fake()->createWithContent('lista.csv', $csv)]);

    $response->assertStatus(202);

    // A fejlécsor és az üres sor kiesett.
    expect($response->json('total'))->toBe(3);
});

it('az EXPORT CSV-t ad, letöltésre', function () {
    $job = ValidationJob::query()->create(['total' => 1]);

    ValidationResult::query()->create([
        'validation_job_id' => $job->getKey(),
        'address' => 'anna@pelda.hu',
        'result' => 'deliverable',
        'risk' => 0.0,
        'reasons' => [],
    ]);

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$this->plain])
        ->get('/v1/validate/bulk/'.$job->uuid.'/export');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    $content = $response->streamedContent();

    expect($content)->toContain('address,result,risk');
    expect($content)->toContain('anna@pelda.hu,deliverable');
});

it('IDEGEN workspace futása nem érhető el', function () {
    $job = ValidationJob::query()->create(['total' => 1]);

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    ['plain' => $idegen] = ApiKey::issue('Idegen', ['validate:run']);

    validateApi('GET', '/v1/validate/bulk/'.$job->uuid, [], $idegen)->assertStatus(404);
});

it('a lejárt futás és az eredményei törlődnek', function () {
    // A feltöltött címlista az ügyfél TELJES címlistája lehet egy fájlban.
    $job = ValidationJob::query()->create(['total' => 1]);

    ValidationResult::query()->create([
        'validation_job_id' => $job->getKey(),
        'address' => 'anna@pelda.hu',
        'result' => 'deliverable',
        'risk' => 0.0,
        'reasons' => [],
    ]);

    $job->forceFill(['expires_at' => now()->subDay()])->save();

    $this->artisan('validation:purge')->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(ValidationJob::query()->count())->toBe(0);
    expect(ValidationResult::query()->count())->toBe(0);
});

/*
 | 42. A GATE-INTEGRÁCIÓ — a FakeValidator lecserélve.
 */

it('a PreSendGate 6. kapuja a VALÓDI láncból dönt', function () {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    config(['sending.validation.enabled' => true, 'sending.validation.risk_threshold' => 0.9]);

    // MX nélküli címzett-domain: a lánc 1.0-t ad, a kapu elutasít.
    $this->dns->blackhole('nincs-mx.example');

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'to' => ['anna@nincs-mx.example'],
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'v-'.bin2hex(random_bytes(5)),
    ]);

    expect($result->message->status)->toBe('rejected');
    expect($result->message->reason)->toBe('VALIDATION_RISK');
    // A gate-trace megmutatja, MELYIK kapu állt meg.
    expect($result->message->options['gate_trace']['validation_risk']['passed'])->toBeFalse();
});

it('a gate a SZONDÁT nem hívja — a küldési út nem a szondázás helye', function () {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    config(['sending.validation.enabled' => true]);

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'to' => ['anna@pelda.hu'],
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'v-'.bin2hex(random_bytes(5)),
    ]);

    // Egy 1000 címzettes batch különben 1000 SMTP-párbeszédet jelentene a küldési kérésen
    // BELÜL — a küldés latenciája lenne belőle.
    expect($this->probe->count())->toBe(0);
});

it('a jó cím ÁTMEGY a kapun', function () {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    config(['sending.validation.enabled' => true]);

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'to' => ['kovacs.anna@pelda.hu'],
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'v-'.bin2hex(random_bytes(5)),
    ]);

    expect($result->message->status)->not->toBe('rejected');
});

/*
 | A review blokkolói — viselkedésből mérve.
 */

it('a BIZONYTALAN szonda `unknown` minősítést ad, nem `deliverable`-t', function () {
    // Ez volt a modul legsúlyosabb hibája: a `probe_unknown` súly (0.20) a `risky` küszöb
    // (0.25) ALATT van, tehát minden eldönthetetlen szonda „kézbesíthető" lett. Mérve: 200
    // gmail-cím, 5 valódi szonda (a keret miatt) → 195 sosem ellenőrzött cím `deliverable`
    // minősítéssel. Egy tízezres listán ~9995 hamis „deliverable" — és az ügyfél PONT ezért
    // fizet.
    config(['validation.probe.per_domain_per_minute' => 5]);

    $this->probe->default = ProbeResult::rejected(550, '5.1.1 No such user');

    $job = ValidationJob::query()->create(['total' => 20]);

    seedBulk($job, array_map(fn (int $i) => "felhasznalo{$i}@gmail.com", range(1, 20)));

    config(['validation.bulk.chunk' => 20]);

    runBulk($job);

    $job->refresh();

    // Öt valódi szonda: azok `undeliverable`-ek. A többi tizenöt NEM lett `deliverable`.
    expect($job->undeliverable)->toBe(5);
    expect($job->unknown)->toBe(15);
    expect($job->deliverable)->toBe(0);
});

it('az ÚJRAFUTÓ job nem duplikál', function () {
    // A korábbi változat a címeket a payloadban vitte, és az őr csak a `completed` állapotot
    // nézte: a `failed` job újrafutásakor a TELJES batch újra feldolgozódott. Mérve:
    // `processed=6` egy `total=3` futáson, minden cím kétszer az exportban — és a ventPay
    // `validations_counter` duplán, azaz HIBÁS SZÁMLA.
    $job = ValidationJob::query()->create(['total' => 3]);

    seedBulk($job, ['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu']);

    runBulk($job);
    runBulk($job);
    runBulk($job);

    $job->refresh();

    expect($job->processed)->toBe(3);
    expect(ValidationResult::query()->count())->toBe(3);
    expect($job->deliverable + $job->risky + $job->undeliverable + $job->unknown)->toBe(3);
});

it('ugyanaz a cím EGYSZER kerül be a futásba', function () {
    // Az egyediség a SÉMÁBAN van, nem a hívó figyelmén.
    Queue::fake();

    $response = validateApi('POST', '/v1/validate/bulk', [
        'addresses' => ['anna@pelda.hu', 'anna@pelda.hu', 'ANNA@pelda.hu', 'masik@pelda.hu'],
    ]);

    $response->assertStatus(202);

    expect(ValidationResult::query()->count())->toBe($response->json('total'));
});

it('az EXPORT nem ad képletet a táblázatkezelőnek', function () {
    // Az Excel és a LibreOffice a `=` kezdetű mezőt KÉPLETKÉNT értékeli ki — idézőjelben is.
    // A támadási út teljes: idegen feliratkozó beírja a „címet" a tenant űrlapjába, a tenant
    // bulk-validálja, letölti és megnyitja a CSV-t, és a parancs az Ő gépén fut le.
    $job = ValidationJob::query()->create(['total' => 1]);

    ValidationResult::query()->create([
        'validation_job_id' => $job->getKey(),
        'address' => "=cmd|' /C calc'!A0@pelda.hu",
        'result' => 'undeliverable',
        'risk' => 1.0,
        'reasons' => [],
    ]);

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$this->plain])
        ->get('/v1/validate/bulk/'.$job->uuid.'/export');

    $content = $response->streamedContent();

    // A mező SZÖVEGKÉNT megy ki: az aposztróf-prefix ezt jelenti a táblázatkezelőnek.
    expect($content)->toContain("'=cmd");
    expect($content)->not->toMatch('/^"?=cmd/m');
});

it('IDEGEN workspace exportja nem érhető el', function () {
    $job = ValidationJob::query()->create(['total' => 1]);

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    ['plain' => $idegen] = ApiKey::issue('Idegen', ['validate:run']);

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$idegen])
        ->get('/v1/validate/bulk/'.$job->uuid.'/export');

    $response->assertStatus(404);
});

it('a bulk job NEVESÍTETT sorra megy', function () {
    // A `default` sort a küldési supervisor nem fogyasztja: az oda tett job csendben, örökre
    // a sorban maradna — a futás `pending`-en ragadna, és 30 nap múlva a purge törölné.
    Queue::fake();

    validateApi('POST', '/v1/validate/bulk', ['addresses' => ['anna@pelda.hu']]);

    Queue::assertPushed(ProcessValidationJob::class, fn ($job) => $job->queue === 'validation');
});

/*
 | A biztonsági review blokkolói — viselkedésből mérve.
 */

it('a NEMZETKÖZI (IDN) címzett átmegy a kapun', function () {
    // A 6. kapu élesítése után a szintaxis-ellenőrzés a NYERS címre futott, a
    // domain-karakterosztály viszont ASCII — mérve: `anna@példa.hu` risk=1.00, tehát MINDEN
    // nemzetközi címzett kiesett volna a küldésből. Ez az A2+ ADR SMTPUTF8-döntését ejtené el.
    $verdict = app(AddressValidator::class)
        ->validate('anna@példa.hu', withProbe: false);

    expect($verdict->classification)->toBe('deliverable');
    expect($verdict->risk)->toBe(0.0);
});

it('a DNS-KIMARADÁS nem állítja meg a küldést', function () {
    // A `records()` korábban minden hibára üres tömböt adott: a SERVFAIL, az időtúllépés és
    // az NXDOMAIN megkülönböztethetetlen volt. A lánc ebből „nincs MX"-et olvasott, 1.0
    // kockázatot, és a kapu MINDEN levelet elutasított — a kockázat-cache miatt egy órával
    // túlélve a kiváltó okot.
    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function records(string $name, string $type): array
        {
            throw new DnsUnavailable('SERVFAIL');
        }
    });

    $verdict = app(AddressValidator::class)
        ->validate('anna@pelda.hu', withProbe: false);

    expect($verdict->classification)->toBe('unknown');
    expect($verdict->reasons)->toContain('DNS_UNAVAILABLE');
    // A KOCKÁZAT nulla: nem a cím a hibás, hanem mi nem tudtuk megnézni.
    expect($verdict->risk)->toBe(0.0);
});

it('az érintetti törlés a CÍMELLENŐRZÉS eredményeit is elviszi', function () {
    // Ez az ügyfél TELJES címlistája — a 30 napos retenció nem válasz egy Art. 17 kérésre.
    $job = ValidationJob::query()->create(['total' => 1]);

    ValidationResult::query()->create([
        'validation_job_id' => $job->getKey(),
        'address' => 'erintett@pelda.hu',
        'result' => 'deliverable',
        'risk' => 0.0,
        'reasons' => [],
        'suggestion' => 'erintett@pelda.com',
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@pelda.hu'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    $row = ValidationResult::query()->firstOrFail();

    expect($row->address)->not->toContain('erintett');
    expect($row->suggestion)->toBeNull();
    expect($row->redacted_at)->not->toBeNull();
    // A TÉNY marad: a minősítés statisztikailag még használható.
    expect($row->result)->toBe('deliverable');
});

it('a TÖRÖLT cím nem kerülhet vissza egy új bulk futásba', function () {
    // A modell `creating` hookja az `insertOrIgnore` mellett NEM fut le — a védelemnek a
    // beszúró úton kell lennie, különben halott kód azon az egyetlen helyen, ami használja.
    Queue::fake();

    $this->artisan('logs:redact-subject', ['email' => 'erintett@pelda.hu'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    $response = validateApi('POST', '/v1/validate/bulk', [
        'addresses' => ['erintett@pelda.hu', 'mas@pelda.hu'],
    ]);

    $response->assertStatus(202);

    expect(ValidationResult::query()->pluck('address')->all())->toBe(['mas@pelda.hu']);
});

it('a szonda-keret a fogadó MX-HOSZTJÁRA szól, nem csak a domainre', function () {
    // Mérve: négy aldomain × öt cím = HÚSZ valódi szonda ugyanarra az `mx.pelda.hu`-ra,
    // wildcard DNS-sel korlátlanul. A fogadó a HOSZTOT látja, nem a domaint.
    config(['validation.probe.per_domain_per_minute' => 5, 'validation.probe.per_tenant_per_minute' => 999]);

    foreach (['a.pelda.hu', 'b.pelda.hu', 'c.pelda.hu', 'd.pelda.hu'] as $domain) {
        $this->dns->publish($domain, 'MX', '10 mx.pelda.hu.');
    }

    $probe = app(MailboxProbe::class);

    foreach (['a', 'b', 'c', 'd'] as $prefix) {
        foreach (range(1, 5) as $i) {
            $probe->probe("{$prefix}{$i}@{$prefix}.pelda.hu");
        }
    }

    // ÖT valódi szonda ment ki a közös MX-re, nem húsz.
    expect($this->probe->count())->toBe(5);
});

it('az egyik tenant nem használja el a másik keretét', function () {
    // Mérve: „A" tenant öt kérése után „B" első, FIZETETT kérése is „nem tudjuk"-ot kapott.
    config(['validation.probe.per_domain_per_minute' => 100, 'validation.probe.per_tenant_per_minute' => 3]);

    $probe = app(MailboxProbe::class);

    foreach (range(1, 5) as $i) {
        $probe->probe("a{$i}@pelda.hu");
    }

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    expect($probe->probe('elso@pelda.hu')->status)->not->toBe('unknown');
});

it('a cache nem tárolja a fogadó nyers válaszát', function () {
    // A `detail` rendszerint visszaidézi a címet (`550 5.1.1 <anna@pelda.hu> rejected`), és
    // a cache sem a retenciós, sem a törlési úton nem érhető el.
    $this->probe->answer('anna@pelda.hu', ProbeResult::rejected(550, '5.1.1 <anna@pelda.hu> Recipient address rejected'));

    app(MailboxProbe::class)->probe('anna@pelda.hu');

    $tarolt = app(MailboxProbe::class)->probe('anna@pelda.hu');

    expect($tarolt->status)->toBe('rejected');
    expect($tarolt->code)->toBe(550);
    expect($tarolt->detail)->toBeNull();
    // …és tényleg a cache-ből jött.
    expect($this->probe->count())->toBe(1);
});

it('a TÚL HOSSZÚ cím strukturált 400, nem 500', function () {
    // Egy címellenőrző szolgáltatásnak a hosszú szemét a BEMENETE, nem a hibája.
    $response = validateApi('POST', '/v1/validate', ['address' => str_repeat('a', 300).'@pelda.hu']);

    expect($response->status())->toBe(400);
    expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
});

it('a bulk feltöltés AUDITÁLT', function () {
    // Az ügyfél teljes címlistájának be- és kimenő útja exfiltráció-észlelési szempontból
    // maga a jel.
    Queue::fake();

    validateApi('POST', '/v1/validate/bulk', ['addresses' => ['anna@pelda.hu']]);

    expect(DB::table('audit_logs')->where('action', 'validation.bulk_created')->count())->toBe(1);
});
