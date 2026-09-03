<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Logs\Services\MessageLogQuery;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | A3 · 25. kritérium és golden rule 10 — a napló-melléktáblák RETENCIÓJA.
 |
 | Mindhárom tábla PII-t tárol: a címzett címét, a fogadó szerver válaszát (ami gyakran az
 | eredeti tárgyat idézi) és a webhook-payloadot (címzett + `v:*` változók). A lejárat után
 | ezeknek TÖRLŐDNIÜK kell — az aggregátum marad, a nyers sor nem.
 */

beforeEach(function () {
    $this->app->instance(HostResolver::class, new FakeHostResolver);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
});

/**
 * A parancs a végén ELENGEDI a kontextust (hosszan élő worker: a bent felejtett kontextus a
 * következő futás adatait idegen tenantnak könyvelné el). A teszt tehát VISSZAÁLLÍTJA —
 * enélkül az utána következő lekérdezés kontextus nélkül fut, a global scope `1 = 0`-t ad,
 * és minden ellenőrzés ÜRES eredményt látna: a takarítás akkor is „sikeresnek" tűnne, ha
 * egyetlen sort sem törölt.
 */
function purgeAndReturn(Workspace $workspace): void
{
    test()->artisan('logs:purge')->assertSuccessful();

    app(WorkspaceContext::class)->set($workspace);
}

it('a lejárt sorokat törli, a frisseket meghagyja', function () {
    $lejart = SmtpTransaction::create([
        'message_uuid' => (string) Str::uuid(),
        'recipient' => 'regi@example.test',
        'response_code' => 250,
    ]);
    $lejart->forceFill(['expires_at' => now()->subDay()])->save();

    SmtpTransaction::create([
        'message_uuid' => (string) Str::uuid(),
        'recipient' => 'friss@example.test',
        'response_code' => 250,
    ]);

    purgeAndReturn($this->workspace);

    expect(SmtpTransaction::query()->pluck('recipient')->all())->toBe(['friss@example.test']);
});

it('a LEJÁRAT NÉLKÜLI régi sor is takarodik', function () {
    // Az `expires_at`-ot a modell `creating` hookja tölti. A modellt megkerülő beszúrás
    // (tömeges insert, migrációval betöltött sor) `NULL`-t hagy — a puszta
    // `expires_at <= now()` ezt SOSEM fogná meg, és a PII örökre bent maradna.
    DB::table('smtp_transactions')->insert([
        'workspace_id' => $this->workspace->getKey(),
        'message_uuid' => (string) Str::uuid(),
        'recipient' => 'orokre@example.test',
        'response_code' => 250,
        'expires_at' => null,
        'created_at' => now()->subYears(3),
        'updated_at' => now()->subYears(3),
    ]);

    purgeAndReturn($this->workspace);

    expect(SmtpTransaction::query()->count())->toBe(0);
});

it('a lejárat nélküli FRISS sort viszont nem bántja', function () {
    DB::table('smtp_transactions')->insert([
        'workspace_id' => $this->workspace->getKey(),
        'message_uuid' => (string) Str::uuid(),
        'recipient' => 'tegnapi@example.test',
        'response_code' => 250,
        'expires_at' => null,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    purgeAndReturn($this->workspace);

    expect(SmtpTransaction::query()->count())->toBe(1);
});

it('mindhárom PII-táblát takarítja', function () {
    ['endpoint' => $endpoint] = WebhookEndpoint::issue('CRM', 'https://hook.example.test/w', []);

    $attempt = DeliveryAttempt::create([
        'message_uuid' => (string) Str::uuid(),
        'recipient' => 'regi@example.test',
        'attempt' => 1,
        'code' => 550,
        'response' => '550 No such user',
        'classification' => 'hard',
    ]);
    $attempt->forceFill(['expires_at' => now()->subDay()])->save();

    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event_type' => 'delivered',
        'message_uuid' => (string) Str::uuid(),
        'payload' => ['message' => ['recipient' => 'regi@example.test']],
        'status' => 'delivered',
    ]);
    $delivery->forceFill(['expires_at' => now()->subDay()])->save();

    purgeAndReturn($this->workspace);

    expect(DeliveryAttempt::query()->count())->toBe(0);
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('a MÁSIK tenant lejárt sorát is takarítja — nem csak az elsőét', function () {
    // A parancs tenantonként megy, kontextussal (golden rule 6): ha a ciklus elakadna az
    // elsőn, a többiek PII-je csendben bent maradna.
    $masik = Workspace::factory()->create();

    foreach ([$this->workspace, $masik] as $workspace) {
        app(WorkspaceContext::class)->set($workspace);

        $sor = SmtpTransaction::create([
            'message_uuid' => (string) Str::uuid(),
            'recipient' => 'regi@example.test',
            'response_code' => 250,
        ]);
        $sor->forceFill(['expires_at' => now()->subDay()])->save();
    }

    $this->artisan('logs:purge')->assertSuccessful();

    foreach ([$this->workspace, $masik] as $workspace) {
        app(WorkspaceContext::class)->set($workspace);

        expect(SmtpTransaction::query()->count())->toBe(0);
    }
});

/*
 | ÉRINTETTI TÖRLÉSI KÉRÉS (GDPR Art. 17).
 |
 | A retenció IDŐ alapján takarít, ez SZEMÉLY alapján. Egy törlési kérésre nem válasz az,
 | hogy „kilencven nap múlva magától eltűnik".
 */

it('a törlési kérés MIND a négy PII-tárolóból eltünteti a személyt', function () {
    // A `MessageEvent::created` hookja webhookot indít; `sync` queue-nál az MOST futna le,
    // valódi HTTP-hívással. A tesztnek a REDAKCIÓRÓL kell szólnia, nem a kézbesítésről.
    Queue::fake();

    ['endpoint' => $endpoint] = WebhookEndpoint::issue('CRM', 'https://hook.example.test/w', []);

    $uuid = (string) Str::uuid();

    MessageEvent::create([
        'message_uuid' => $uuid,
        'type' => 'delivered',
        'recipient' => 'erintett@example.test',
        'channel' => 'transactional',
        'tags' => ['szamla'],
        'variables' => ['rendeles' => 'A-42'],
    ]);

    SmtpTransaction::create([
        'message_uuid' => $uuid,
        'recipient' => 'erintett@example.test',
        'response_code' => 250,
        'response_text' => 'Ok: queued for erintett@example.test',
        'lines' => [['direction' => 'out', 'line' => 'RCPT TO:<erintett@example.test>']],
    ]);

    DeliveryAttempt::create([
        'message_uuid' => $uuid,
        'recipient' => 'erintett@example.test',
        'attempt' => 1,
        'code' => 250,
        'response' => '250 Ok',
        'classification' => 'delivered',
    ]);

    WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event_type' => 'delivered',
        'message_uuid' => $uuid,
        'payload' => ['message' => ['recipient' => 'erintett@example.test'], 'variables' => ['rendeles' => 'A-42']],
        'status' => 'delivered',
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    // A SZEMÉLY eltűnt…
    expect(MessageEvent::query()->first()->recipient)->toBeNull();
    expect(MessageEvent::query()->first()->variables)->toBe([]);
    expect(SmtpTransaction::query()->first()->recipient)->toBeNull();
    expect(SmtpTransaction::query()->first()->response_text)->toBeNull();
    expect(SmtpTransaction::query()->first()->lines)->toBe([]);
    expect(DeliveryAttempt::query()->first()->recipient)->toBeNull();
    expect(WebhookDelivery::query()->first()->payload)->toBe([]);

    // …a TÉNY viszont megmaradt: enélkül a kézbesítési statisztika és a panasz-bizonyíték
    // is elveszne, és az anonimizált aggregátum már nem személyes adat.
    expect(MessageEvent::query()->first()->type)->toBe('delivered');
    expect(MessageEvent::query()->first()->redacted_at)->not->toBeNull();
    expect(DeliveryAttempt::query()->first()->code)->toBe(250);
    expect(WebhookDelivery::query()->first()->status)->toBe('delivered');
});

it('a törlési kérés az ÉKEZETES alakot is megtalálja', function () {
    // Tárolva punycode-ban van; az érintett az olvasható alakot írja a kérésébe. Naiv
    // kisbetűsítéssel nulla sort érintenénk — és azt jelentenénk, hogy „nincs mit törölni".
    MessageEvent::create([
        'message_uuid' => (string) Str::uuid(),
        'type' => 'delivered',
        'recipient' => 'user@xn--plda-bpa.hu',
        'channel' => 'transactional',
        'tags' => [],
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'user@példa.hu'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->first()->recipient)->toBeNull();
});

it('MÁS címzett adatát nem bántja', function () {
    foreach (['erintett@example.test', 'masik@example.test'] as $recipient) {
        MessageEvent::create([
            'message_uuid' => (string) Str::uuid(),
            'type' => 'delivered',
            'recipient' => $recipient,
            'channel' => 'transactional',
            'tags' => [],
        ]);
    }

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->whereNotNull('recipient')->pluck('recipient')->all())
        ->toBe(['masik@example.test']);
});

it('a törlés a napló FŐTÁBLÁJÁT is elviszi', function () {
    // A melléktáblák kiürítése önmagában nem teljesítés: a napló-képernyő a `messages`
    // sorból olvassa a címzettet és a tárgyat. Ha az ott marad, a törlés után is kiírjuk az
    // érintettet — és a `MessageLogQuery` meg is találja a nevére keresve.
    Queue::fake();

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Titkos tárgy: lelet',
        'to' => ['erintett@example.test'],
        'html' => '<p>Bizalmas</p>',
        'idempotency_key' => 'r-'.bin2hex(random_bytes(5)),
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    $message = Message::query()->firstOrFail();

    expect($message->recipients)->toBe([]);
    expect($message->subject)->toBeNull();
    expect($message->options['body_html'] ?? null)->toBeNull();
    expect($message->redacted_at)->not->toBeNull();

    // A napló-képernyő fő szűrője sem találja meg többé.
    expect(app(MessageLogQuery::class)->paginate(['recipient' => 'erintett@example.test'])->items())->toBe([]);

    // A nyers MIME sem marad meg: abban a teljes `To:` fejléc és a törzs benne van.
    expect(RawMimeMessage::query()->count())->toBe(0);
});

it('a BATCH többi címzettjének adatát nem viszi el', function () {
    // Egy sorban ezer cím lehet. Az érintettet kivesszük a listából; a többiek megőrzésére
    // jogalapunk van, tehát a tárgyat és a törzset is meghagyjuk.
    Queue::fake();

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Közös levél',
        'to' => ['erintett@example.test', 'marad@example.test'],
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'r-'.bin2hex(random_bytes(5)),
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    $message = Message::query()->firstOrFail();

    expect($message->recipients)->toBe(['marad@example.test']);
    expect($message->subject)->toBe('Közös levél');
});

it('a törlés NEM hagy ki sorokat, ha sok van belőlük', function () {
    // A naiv `each()` OFFSET-tel lapoz, miközben a callback épp azt a feltételt szünteti meg,
    // amire szűrünk: a második lap offsetje már a maradék halmazba mutat, és a sorok
    // negyede-harmada bent marad — miközben a parancs SIKERT jelent. Egy törlési kérésnél ez
    // a legrosszabb fajta hiba: azt mondjuk, teljesítettük.
    $rows = [];

    foreach (range(1, 2500) as $i) {
        $rows[] = [
            'workspace_id' => $this->workspace->getKey(),
            'uuid' => (string) Str::uuid(),
            'message_uuid' => (string) Str::uuid(),
            'type' => 'delivered',
            'recipient' => 'sok@example.test',
            'channel' => 'transactional',
            'tags' => '[]',
            'variables' => '{}',
            'payload' => '{}',
            'occurred_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('message_events')->insert($chunk);
    }

    $this->artisan('logs:redact-subject', ['email' => 'sok@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->whereNotNull('recipient')->count())->toBe(0);
});

it('a törlés UTÁN a nyomkövetés sem írhatja vissza a címet', function () {
    // A már kiküldött levélben ott a pixel, ami titkosítva hordozza a címzettet. Enélkül
    // egyetlen betöltés visszaírná a törölt címet — és a webhookon harmadik félhez is
    // kiküldené. Az Apple MPP és a Gmail képproxyja ezt magától, felhasználói szándék nélkül
    // megteszi: a törlés csak addig tartana, amíg a levél a postafiókban van.
    Queue::fake();

    Domain::create([
        'name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now(),
        'tracking_domain' => 'localhost', 'tracking_status' => 'verified', 'tracking_enabled' => true,
    ]);

    $message = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['erintett@example.test'],
        'html' => '<html><body><p>Szia</p></body></html>',
        'idempotency_key' => 'r-'.bin2hex(random_bytes(5)),
    ])->message;

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'erintett@example.test',
        'localhost',
    );

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    // A pixel VÁLASZA ugyanaz marad (a levélkliens ne mutasson törött képet)…
    $this->get('/t/o/'.$token)->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    // …de új esemény NEM keletkezik.
    expect(MessageEvent::query()->whereNotNull('recipient')->count())->toBe(0);
    expect(ErasedRecipient::erased('erintett@example.test'))->toBeTrue();
});

it('a törlési parancs AUDITÁLT, és a cím maga nem kerül az auditba', function () {
    // Visszavonhatatlan, tenanteken átnyúló admin-művelet (golden rule 11). Egy hatósági
    // kérdésre — „mikor és mit töröltek?" — enélkül nincs válasz; és fordítva: egy téves
    // futtatás után sincs mit kimutatni.
    MessageEvent::create([
        'message_uuid' => (string) Str::uuid(),
        'type' => 'delivered',
        'recipient' => 'erintett@example.test',
        'channel' => 'transactional',
        'tags' => [],
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    $sor = DB::table('audit_logs')->where('action', 'logs.redact_subject')->first();

    expect($sor)->not->toBeNull();

    $context = json_decode((string) $sor->context, true);

    expect($context['recipient_hash'])->toBe(ErasedRecipient::hash('erintett@example.test'));
    expect($context['affected']['events'])->toBe(1);
    // A CÍM maga nem kerül bele: különben az audit-napló lenne a legérzékenyebb címlistánk.
    expect((string) $sor->context)->not->toContain('erintett@example.test');
});

it('a SZÁRAZ FUTÁS megszámol, de nem ír', function () {
    MessageEvent::create([
        'message_uuid' => (string) Str::uuid(),
        'type' => 'delivered',
        'recipient' => 'erintett@example.test',
        'channel' => 'transactional',
        'tags' => [],
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test', '--dry-run' => true])
        ->expectsOutputToContain('SZÁRAZ FUTÁS')
        ->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->whereNotNull('recipient')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'logs.redact_subject')->count())->toBe(0);
    expect(ErasedRecipient::query()->count())->toBe(0);
});

it('a --workspace kapcsoló NEM nyúl a másik tenanthoz', function () {
    $masik = Workspace::factory()->create();

    foreach ([$this->workspace, $masik] as $workspace) {
        app(WorkspaceContext::class)->set($workspace);

        MessageEvent::create([
            'message_uuid' => (string) Str::uuid(),
            'type' => 'delivered',
            'recipient' => 'erintett@example.test',
            'channel' => 'transactional',
            'tags' => [],
        ]);
    }

    $this->artisan('logs:redact-subject', [
        'email' => 'erintett@example.test',
        '--workspace' => $this->workspace->uuid,
    ])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);
    expect(MessageEvent::query()->whereNotNull('recipient')->count())->toBe(0);

    app(WorkspaceContext::class)->set($masik);
    expect(MessageEvent::query()->whereNotNull('recipient')->count())->toBe(1);
});
