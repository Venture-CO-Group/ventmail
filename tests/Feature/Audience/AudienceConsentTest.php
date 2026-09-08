<?php

declare(strict_types=1);

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Contracts\VentureOsSync;
use App\Modules\Audience\Data\CrmContact;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Services\ContactGdpr;
use App\Modules\Audience\Services\ContactSynchroniser;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Audience\Testing\FakeContactIndex;
use App\Modules\Audience\Testing\FakeVentureOsSync;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Consent\Services\ConfirmationTokens;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\DatabaseConsentChecker;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | B1 · 49–57. kritérium — közönség, consent, űrlapok, preference center.
 |
 | A packet TÉTJE egy mondatban: innentől a PreSendGate 3. kapuja VALÓDI adatból dönt. Az
 | M0-3 óta a `FakeConsentChecker` egy in-memory tömböt kérdezett, tehát élesben MINDEN
 | marketing levél átment volna a consent-kapun.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

/*
 | 49. LISTA / CÍMKE / SZEGMENS
 */

it('a szegmens-motor DETERMINISZTIKUS: ugyanaz a definíció ugyanazt a halmazt adja', function () {
    // A determinizmus a kampány megismételhetőségének alapja: a B2 célzása és a B4
    // analitikája UGYANEZT a motort hívja, és a „mennyi címzett volt?" kérdésre ugyanazt a
    // választ kell adnia egy nappal később is.
    foreach (range(1, 20) as $i) {
        Contact::create([
            'email' => "c{$i}@example.test",
            'status' => $i % 2 === 0 ? 'subscribed' : 'pending',
            'attributes' => ['city' => $i % 3 === 0 ? 'Budapest' : 'Debrecen'],
        ]);
    }

    $definition = [
        'match' => 'all',
        'rules' => [
            ['field' => 'status', 'op' => 'is', 'value' => 'subscribed'],
            ['field' => 'attr:city', 'op' => 'is', 'value' => 'Budapest'],
        ],
    ];

    $engine = app(SegmentEngine::class);

    $first = $engine->query($definition)->pluck('email')->all();
    $second = $engine->query($definition)->pluck('email')->all();

    expect($first)->toBe($second);
    expect($first)->not->toBeEmpty();
    // 6, 12, 18 — páros ÉS hárommal osztható.
    expect($first)->toHaveCount(3);
});

it('a szegmens-DSL ZÁRT: ismeretlen mező és operátor KIVÉTEL, nem csendes eldobás', function () {
    // A csendes eldobás itt különösen rossz: egy elhagyott szabályból SZÉLESEBB szegmens
    // lesz, tehát TÖBB embernek megy ki a levél, mint amit a felhasználó beállított.
    $engine = app(SegmentEngine::class);

    expect(fn () => $engine->query(['rules' => [['field' => 'password', 'op' => 'is', 'value' => 'x']]]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $engine->query(['rules' => [['field' => 'email', 'op' => 'regexp', 'value' => 'x']]]))
        ->toThrow(InvalidArgumentException::class);

    // Az attribútum-KULCS is a felhasználótól jön, és JSON-útvonalként kerül a lekérdezésbe.
    expect(fn () => $engine->query(['rules' => [['field' => "attr:a'b", 'op' => 'is', 'value' => 'x']]]))
        ->toThrow(InvalidArgumentException::class);
});

it('az ÜRES szabálylista a teljes elérhető közönség — nem az üres halmaz', function () {
    // A két értelmezés egymás ellentéte, és a rossz választás csendes: egy elrontott
    // szerkesztő nulla címzettet adna egy kampánynak.
    Contact::create(['email' => 'a@example.test', 'status' => 'subscribed']);
    Contact::create(['email' => 'b@example.test', 'status' => 'unsubscribed']);

    expect(app(SegmentEngine::class)->count(['rules' => []]))->toBe(1);
});

it('a lista- és címke-tagság szegmentálható, és a leiratkozott tagság NEM tagság', function () {
    $list = ContactList::create(['name' => 'Hírlevél']);
    $tag = ContactTag::create(['name' => 'vip']);

    $bent = Contact::create(['email' => 'bent@example.test', 'status' => 'subscribed']);
    $kint = Contact::create(['email' => 'kint@example.test', 'status' => 'subscribed']);

    $bent->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()]);
    // A LEIRATKOZOTT sor ottmarad (a tény bizonyíték), de a szegmensbe nem tartozik bele.
    $kint->lists()->attach($list, ['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
    $bent->tags()->attach($tag);

    $engine = app(SegmentEngine::class);

    expect($engine->query(['rules' => [['field' => 'x', 'op' => 'in_list', 'value' => $list->uuid]]])
        ->pluck('email')->all())->toBe(['bent@example.test']);

    expect($engine->query(['rules' => [['field' => 'x', 'op' => 'has_tag', 'value' => $tag->uuid]]])
        ->pluck('email')->all())->toBe(['bent@example.test']);
});

/*
 | 50. DOUBLE OPT-IN — végponttól végpontig
 */

it('az űrlap-beiratkozás double opt-innal MEGERŐSÍTETT consentet ad — de csak a linkre kattintás UTÁN', function () {
    $list = ContactList::create(['name' => 'Hírlevél', 'from_email' => 'no-reply@pelda.hu']);

    $form = Form::create([
        'contact_list_id' => $list->getKey(),
        'name' => 'Feliratkozás',
        'consent_text' => 'Hozzájárulok, hogy hírlevelet kapjak a Példa Kft.-től.',
        'policy_version' => '2026-09',
    ]);

    app(SubmitForm::class)($form, new FormSubmission(
        email: 'Anna@Pelda.hu',
        firstName: 'Anna',
        elapsedSeconds: 12,
        ip: '203.0.113.9',
        userAgent: 'Mozilla/5.0',
    ));

    // A BEKÜLDÉS MAGA nem ad jogalapot: a kontakt és a consent `pending`.
    $contact = Contact::query()->firstOrFail();

    expect($contact->email)->toBe('anna@pelda.hu');      // normalizálva
    expect($contact->status)->toBe('pending');

    $consent = Consent::query()->firstOrFail();

    expect($consent->status)->toBe('pending');
    expect($consent->isConfirmed())->toBeFalse();

    // …és a megerősítő levél TRANZAKCIÓS csatornán megy ki.
    $sent = Message::query()->firstOrFail();

    expect($sent->channel)->toBe('transactional');
    expect(FakeDriver::$sent)->toHaveCount(1);

    // A LINKRE kattintás után lesz jogalap.
    $token = app(ConfirmationTokens::class)
        ->issue((int) $this->workspace->getKey(), 'anna@pelda.hu', 'marketing', $list->uuid);

    $this->get('/c/'.$token)->assertOk()->assertSee('megerősítve', false);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Consent::query()->firstOrFail()->isConfirmed())->toBeTrue();
    expect(Contact::query()->firstOrFail()->status)->toBe('subscribed');
    expect(Contact::query()->firstOrFail()->lists()->wherePivot('status', 'subscribed')->count())->toBe(1);
});

it('a consent BIZONYÍTÉKA rögzíti a jogalapot, a forrást, a verziót ÉS amit a felhasználó LÁTOTT', function () {
    // Egy hatósági kérdésre a „consent=true" semmit nem ér: a szöveg mindent.
    $recorder = app(ConsentRecorder::class);

    $consent = $recorder->request(
        email: 'anna@pelda.hu',
        source: 'form',
        ip: '203.0.113.9',
        userAgent: 'Mozilla/5.0',
        policyVersion: '2026-09',
        formText: 'Hozzájárulok, hogy hírlevelet kapjak.',
    );

    $recorder->confirm($consent, '203.0.113.9', 'Mozilla/5.0');

    $events = ConsentEvent::query()->orderBy('id')->get();

    expect($events->pluck('type')->all())->toBe(['requested', 'confirmed']);
    expect($events->first()->form_text)->toBe('Hozzájárulok, hogy hírlevelet kapjak.');
    expect($events->first()->policy_version)->toBe('2026-09');
    expect($events->first()->source)->toBe('form');
});

/*
 | 51. A 3. KAPU VALÓDI ADATBÓL
 */

it('a 3. kapu VALÓDI consent-táblából dönt — nem fake-ből', function () {
    // Ez a packet tétje: az M0-3 óta a kapu egy in-memory tömböt kérdezett.
    expect(app(ConsentChecker::class))
        ->toBeInstanceOf(DatabaseConsentChecker::class);
});

it('MEGERŐSÍTETLEN kontaktra a marketing levél ELAKAD a kapun', function () {
    app(ConsentRecorder::class)->request('anna@pelda.hu');   // `pending` marad

    $result = app(SendMessage::class)([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Hírlevél',
        'text' => 'Szia',
        'idempotency_key' => 'b1-'.bin2hex(random_bytes(5)),
    ]);

    expect($result->decision->allowed)->toBeFalse();
    expect((string) $result->decision->reason)->toBe('NO_CONSENT');
});

it('MEGERŐSÍTETT consenttel átmegy — VISSZAVONÁS után viszont újra elakad', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu');
    $recorder->confirm($consent);

    $send = fn () => app(SendMessage::class)([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Hírlevél',
        'text' => 'Szia',
        'idempotency_key' => 'b1-'.bin2hex(random_bytes(5)),
    ]);

    expect($send()->decision->allowed)->toBeTrue();

    $recorder->revoke($consent->fresh(), 'test');

    expect($send()->decision->allowed)->toBeFalse();
});

it('a kapu FAIL-CLOSED: ismeretlen cím = nincs consent', function () {
    // A „nem találtam, tehát valószínűleg rendben" pont az a gondolat, ami miatt a
    // marketing consent nélkül kimegy.
    expect(app(ConsentChecker::class)->hasConfirmedConsent('soha@example.test', 'marketing'))->toBeFalse();

    expect(app(ConsentChecker::class)->withoutConsent(['a@example.test', 'b@example.test'], 'marketing'))
        ->toBe(['a@example.test', 'b@example.test']);
});

/*
 | 52–53. GDPR és a NYERS IP
 */

it('a GDPR-export tartalmazza a kontaktot, a consentet, a TÖRTÉNETET és az aktivitást', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu', 'marketing', 'form', '203.0.113.9', 'UA', '2026-09', 'Látott szöveg');
    $recorder->confirm($consent);

    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed']);
    $list = ContactList::create(['name' => 'Hírlevél']);
    $contact->lists()->attach($list, ['status' => 'subscribed']);

    $export = app(ContactGdpr::class)->export($contact->fresh());

    expect($export['contact']['email'])->toBe('anna@pelda.hu');
    expect($export['lists'])->toHaveCount(1);
    expect($export['consents'][0]['status'])->toBe('confirmed');
    // A TÖRTÉNET külön: az állapotból nem derül ki, MIKOR és MILYEN SZÖVEGGEL adta.
    expect(array_column($export['consent_history'], 'type'))->toBe(['requested', 'confirmed']);
    expect($export['consent_history'][0]['form_text'])->toBe('Látott szöveg');
    expect($export)->toHaveKey('activity');
});

it('a GDPR-törlés anonimizál, DE a suppression MEGMARAD — felismerhetően', function () {
    // Ez a kritérium lelke. Ha a suppression-sort törölnénk, egy későbbi import ugyanazt a
    // címet újra felvenné — vagyis a törlési kérés eredménye az lenne, hogy MEGINT kap levelet.
    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed']);

    Suppression::create(['email' => 'anna@pelda.hu', 'reason' => 'unsubscribe', 'applies_to' => 'marketing']);

    app(ConsentRecorder::class)->request('anna@pelda.hu');

    $hash = KeyedHash::of('email', 'anna@pelda.hu');

    $summary = app(ContactGdpr::class)->erase($contact->fresh());

    expect($summary['suppressions_kept'])->toBe(1);

    // A TILTÁS megmaradt, és a hash alapján továbbra is FELISMERHETŐ…
    $suppression = Suppression::query()->firstOrFail();

    expect($suppression->email)->toBe('');
    expect($suppression->email_hash)->toBe($hash);

    // …a nyers cím viszont eltűnt mindenhonnan.
    expect(Contact::withTrashed()->firstOrFail()->email)->not->toContain('anna');
    expect(Consent::query()->firstOrFail()->email)->toBe('');
    expect(Consent::query()->firstOrFail()->email_hash)->toBe($hash);
});

it('NYERS IP-t sehol nem tárolunk — csak peppered hash-t', function () {
    app(ConsentRecorder::class)->request('anna@pelda.hu', 'marketing', 'form', '203.0.113.9', 'Mozilla/5.0');

    $event = ConsentEvent::query()->firstOrFail();

    expect($event->ip_hash)->toBe(KeyedHash::of('ip', '203.0.113.9'));
    expect($event->ip_hash)->not->toContain('203.0.113');
    expect($event->user_agent_hash)->toBe(KeyedHash::of('user_agent', 'Mozilla/5.0'));

    // …és a nyers IP a teljes sorban sem szerepel.
    expect(json_encode($event->toArray()))->not->toContain('203.0.113.9');
});

/*
 | 54. ŰRLAP — BOT-SZŰRÉS
 */

it('a HONEYPOT és a TIME-TRAP kiszűri a botot — csendben, ugyanazzal a válasszal', function () {
    // A visszajelzés a támadót tanítaná: „bot vagy" után a következő próbálkozás
    // megkerülné a csapdát.
    $form = Form::create(['name' => 'Feliratkozás', 'min_seconds' => 3]);

    $submit = app(SubmitForm::class);

    // 1. kitöltött csapda
    $submit($form, new FormSubmission(email: 'bot1@example.test', honeypot: 'http://spam.example', elapsedSeconds: 30));

    // 2. túl gyors beküldés
    $submit($form, new FormSubmission(email: 'bot2@example.test', elapsedSeconds: 1));

    expect(Contact::query()->count())->toBe(0);
    expect(Consent::query()->count())->toBe(0);

    // 3. az EMBER átmegy
    $submit($form, new FormSubmission(email: 'anna@pelda.hu', elapsedSeconds: 12));

    expect(Contact::query()->count())->toBe(1);
});

it('a csapda NEVE űrlaponként más — egy általános bot nem ismeri fel', function () {
    $a = Form::create(['name' => 'A']);
    $b = Form::create(['name' => 'B']);

    expect($a->honeypot_field)->not->toBe($b->honeypot_field);
    expect($a->honeypot_field)->toStartWith('kontakt_');
});

it('a SUPPRESSION-lista erősebb, mint az űrlap', function () {
    // Enélkül a leiratkozás megkerülhető lenne azzal, hogy a címet valaki más (vagy egy bot)
    // újra beírja.
    Suppression::create(['email' => 'anna@pelda.hu', 'reason' => 'complaint', 'applies_to' => 'all']);

    app(SubmitForm::class)(Form::create(['name' => 'F']), new FormSubmission(
        email: 'anna@pelda.hu',
        elapsedSeconds: 12,
    ));

    expect(Contact::query()->count())->toBe(0);
    expect(Consent::query()->count())->toBe(0);
});

it('a publikus űrlap-végpont NÉVTELENÜL is működik, és MINDIG ugyanazt válaszolja', function () {
    $form = Form::create(['name' => 'Feliratkozás']);

    app(WorkspaceContext::class)->forget();

    $ok = $this->post('/f/'.$form->slug, ['email' => 'anna@pelda.hu', '_elapsed' => 12]);
    $bot = $this->post('/f/'.$form->slug, ['email' => 'bot@example.test', $form->honeypot_field => 'x', '_elapsed' => 12]);
    $unknown = $this->post('/f/nem-letezik', ['email' => 'anna@pelda.hu', '_elapsed' => 12]);

    $ok->assertOk();
    expect($bot->getContent())->toBe($ok->getContent());
    expect($unknown->getContent())->toBe($ok->getContent());

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Contact::query()->count())->toBe(1);
});

/*
 | 55. PREFERENCE CENTER — konzisztencia a suppressionnel
 */

it('a preference centerben a „mindenről leiratkozom" UGYANAZT jelenti, mint a one-click', function () {
    // Ez a kritérium lelke: ha a két út más állapotot hagyna, a címzett leiratkozna itt, és
    // a kampány mégis kimenne — a kapu ugyanis a suppression-listát kérdezi.
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu');
    $recorder->confirm($consent);

    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed']);
    $list = ContactList::create(['name' => 'Hírlevél']);
    $contact->lists()->attach($list, ['status' => 'subscribed']);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'anna@pelda.hu');

    $this->post('/p/'.$token, ['unsubscribe_all' => 1])->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    // 1. SUPPRESSION-sor, `marketing` hatókörrel — ezt kérdezi a 2. kapu.
    $suppression = Suppression::query()->firstOrFail();
    expect($suppression->applies_to)->toBe('marketing');
    expect($suppression->reason)->toBe('unsubscribe');

    // 2. VISSZAVONT consent — ezt kérdezi a 3. kapu.
    expect(Consent::query()->firstOrFail()->status)->toBe('revoked');

    // 3. …és a marketing tényleg nem megy ki.
    $result = app(SendMessage::class)([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Hírlevél',
        'text' => 'Szia',
        'idempotency_key' => 'b1-'.bin2hex(random_bytes(5)),
    ]);

    expect($result->decision->allowed)->toBeFalse();
});

it('a preference center LISTÁNKÉNT szűkít — és az utolsó pipa kivétele teljes leiratkozás', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu'));

    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed']);
    $hirlevel = ContactList::create(['name' => 'Hírlevél']);
    $termek = ContactList::create(['name' => 'Termékhírek']);

    $contact->lists()->attach($hirlevel, ['status' => 'subscribed']);
    $contact->lists()->attach($termek, ['status' => 'subscribed']);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'anna@pelda.hu');

    // Csak a Hírlevél marad.
    $this->post('/p/'.$token, ['lists' => [(string) $hirlevel->uuid]])->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    expect($contact->fresh()->lists()->wherePivot('status', 'subscribed')->count())->toBe(1);
    // A consent MEGMARAD: nem iratkozott le mindenről.
    expect(Consent::query()->firstOrFail()->status)->toBe('confirmed');

    // Az UTOLSÓ pipa kivétele viszont teljes leiratkozás — különben a listához nem kötött
    // kampány továbbra is kimenne neki.
    $this->post('/p/'.$token, ['lists' => []])->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Consent::query()->firstOrFail()->status)->toBe('revoked');
    expect(Suppression::query()->count())->toBe(1);
});

it('a preference center GET-je NEM ír', function () {
    // Ugyanaz az érv, mint a leiratkozásnál: a védelmi átjárók link-előtöltése csendben
    // módosítaná a beállításokat.
    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed']);
    $list = ContactList::create(['name' => 'Hírlevél']);
    $contact->lists()->attach($list, ['status' => 'subscribed']);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'anna@pelda.hu');

    $this->get('/p/'.$token)->assertOk()->assertSee('Hírlevél');

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Suppression::query()->count())->toBe(0);
    expect($contact->fresh()->status)->toBe('subscribed');
});

/*
 | 56. ventOS-SZINKRON
 */

it('a ventOS-szinkron IDEMPOTENS: kétszeri behúzás EGY kontaktot ad', function () {
    // A szinkron hálózaton megy, tehát újrapróbálódik. Duplikálva a lista
    // megkétszereződne, és a kampány mindenkinek kétszer mennne ki.
    $fake = new FakeVentureOsSync;
    $fake->remote = [new CrmContact('crm-1', 'anna@pelda.hu', 'Anna', 'Kovács', ['city' => 'Budapest'])];

    $this->app->instance(VentureOsSync::class, $fake);

    $first = app(ContactSynchroniser::class)->pull();
    $second = app(ContactSynchroniser::class)->pull();

    expect($first['created'])->toBe(1);
    expect($second['created'])->toBe(0);
    expect(Contact::query()->count())->toBe(1);
    expect(Contact::query()->firstOrFail()->external_id)->toBe('crm-1');
});

it('a szinkron SOHA nem írja át a leiratkozást', function () {
    // A ventOS egy CRM: nem tudja, és nem is jogosult tudni, hogy valaki leiratkozott a
    // hírlevélről. Enélkül egy CRM-oldali „aktív ügyfél" flag visszahozná a listára.
    Contact::create([
        'email' => 'anna@pelda.hu',
        'external_id' => 'crm-1',
        'status' => 'unsubscribed',
        'unsubscribed_at' => now(),
    ]);

    $fake = new FakeVentureOsSync;
    $fake->remote = [new CrmContact('crm-1', 'anna@pelda.hu', 'Anna', updatedAt: now()->addMinute())];

    $this->app->instance(VentureOsSync::class, $fake);

    app(ContactSynchroniser::class)->pull();

    expect(Contact::query()->firstOrFail()->status)->toBe('unsubscribed');
    // …a NÉV viszont frissült: a szinkron nem no-op.
    expect(Contact::query()->firstOrFail()->first_name)->toBe('Anna');
});

it('a szinkron LAST-WRITE-WINS, és AUDITÁLT — nyers cím nélkül', function () {
    $contact = Contact::create(['email' => 'anna@pelda.hu', 'external_id' => 'crm-1', 'first_name' => 'Helyi']);

    $fake = new FakeVentureOsSync;
    // A távoli oldal RÉGEBBI: nem írja felül.
    $fake->remote = [new CrmContact('crm-1', 'anna@pelda.hu', 'Régi', updatedAt: now()->subDay())];

    $this->app->instance(VentureOsSync::class, $fake);

    $summary = app(ContactSynchroniser::class)->pull();

    expect($summary['skipped'])->toBe(1);
    expect($contact->fresh()->first_name)->toBe('Helyi');

    $audit = AuditLog::query()->where('action', 'audience.sync.pull')->firstOrFail();

    expect($audit->context)->toHaveKey('skipped');
    expect(json_encode($audit->context))->not->toContain('anna@pelda.hu');
});

it('a kitolás CSAK a CRM-azonosítóval rendelkező kontaktokat adja át', function () {
    Contact::create(['email' => 'van@pelda.hu', 'external_id' => 'crm-1']);
    Contact::create(['email' => 'nincs@pelda.hu']);

    $fake = new FakeVentureOsSync;
    $this->app->instance(VentureOsSync::class, $fake);

    expect(app(ContactSynchroniser::class)->push()['pushed'])->toBe(1);
    expect($fake->pushed[0]->email)->toBe('van@pelda.hu');
});

/*
 | 57. APPEND-ONLY BIZONYÍTÉK
 */

it('a consent-bizonyíték APPEND-ONLY — a modellen ÉS az adatbázisban is', function () {
    $consent = app(ConsentRecorder::class)->request('anna@pelda.hu');

    $event = ConsentEvent::query()->firstOrFail();

    // 1. A MODELL hangosan szól (érthető hiba a fejlesztőnél).
    expect(fn () => $event->update(['type' => 'confirmed']))->toThrow(RuntimeException::class);
    expect(fn () => $event->delete())->toThrow(RuntimeException::class);

    // 2. …de a valódi zár az ADATBÁZIS-TRIGGER: a modellt megkerülő írás sem megy át.
    //    Egy bizonyíték-tábla pont attól bizonyíték, hogy utólag nem írható át.
    //
    // MENTÉSPONTBAN mérjük: a Postgresben egy elhasalt utasítás megszakítja a TELJES
    // tranzakciót, és a teszt további lekérdezései „current transaction is aborted"-tal
    // buknának el — a mentéspont visszaállítása után a kapcsolat használható marad.
    foreach (['update', 'delete'] as $operation) {
        DB::beginTransaction();

        $blocked = false;

        try {
            $operation === 'update'
                ? DB::table('consent_events')->where('id', $event->getKey())->update(['type' => 'confirmed'])
                : DB::table('consent_events')->where('id', $event->getKey())->delete();
        } catch (QueryException $e) {
            // A TRIGGER üzenete, nem a modellé: ez bizonyítja, hogy az adatbázis zárt, és
            // nem a `ConsentEvent` hookja fogta meg a `DB::table()`-t (nem is tudná).
            $blocked = str_contains($e->getMessage(), 'megváltoztathatatlan');
        } finally {
            DB::rollBack();
        }

        expect($blocked)->toBeTrue($operation);
    }

    expect(ConsentEvent::query()->firstOrFail()->type)->toBe('requested');
    expect($consent->status)->toBe('pending');
});

it('az ÁLLAPOTVÁLTÁS új eseményt ír, nem átírja a régit', function () {
    $recorder = app(ConsentRecorder::class);

    $consent = $recorder->request('anna@pelda.hu');
    $recorder->confirm($consent);
    $recorder->revoke($consent->fresh(), 'test');

    // A TÖRTÉNET teljes, és sorrendben van: egy jogi vitában nem az aktuális állapot a
    // kérdés, hanem hogy VOLT-E jogalap akkor, amikor küldtünk.
    expect(ConsentEvent::query()->orderBy('id')->pluck('type')->all())
        ->toBe(['requested', 'confirmed', 'revoked']);

    // …és a `consents` sor az AKTUÁLIS állapot, a bizonyítékkal együtt.
    $fresh = $consent->fresh();

    expect($fresh->status)->toBe('revoked');
    expect($fresh->confirmed_at)->not->toBeNull();   // a jogalap ABLAKA megmarad
    expect($fresh->revoked_at)->not->toBeNull();
});

it('a megerősítő token CÉL-ELVÁLASZTOTT és LEJÁRÓ', function () {
    // Az `APP_KEY` közös: cél nélkül egy leiratkozó vagy nyomkövetési token ÉRVÉNYES
    // megerősítő tokenné válna — és a megerősítés JOGALAPOT teremt, tehát egy elkapott
    // nyomkövetési tokennel bárki „hozzájárulást" gyárthatna valaki más nevében.
    $tokens = app(ConfirmationTokens::class);

    $unsubscribe = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), (string) Str::uuid(), 'anna@pelda.hu');

    expect($tokens->parse($unsubscribe))->toBeNull();

    $prefs = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'anna@pelda.hu');

    expect($tokens->parse($prefs))->toBeNull();

    // …és a LEJÁRT megerősítő link ugyanaz a válasz, mint a hamis.
    $token = $tokens->issue((int) $this->workspace->getKey(), 'anna@pelda.hu', 'marketing');

    expect($tokens->parse($token))->not->toBeNull();

    $this->travel(config('consent.confirmation_ttl_hours') + 1)->hours();

    expect($tokens->parse($token))->toBeNull();
});

it('a kontakt mentése FRISSÍTI a keresőindexet, a törlés KIVESZI belőle', function () {
    // A keresés SOHA nem lehet a mentés előfeltétele (ezért job), de a mentés SOHA nem
    // hagyhatja el az indexelést (ezért modell-hook, nem hívási hely): a kontakt hat úton
    // keletkezik vagy változik, és ami a fejlesztő figyelmén múlik, az valahol kimarad.
    $index = new FakeContactIndex;
    $this->app->instance(ContactSearchIndex::class, $index);

    $contact = Contact::create(['email' => 'anna@pelda.hu', 'first_name' => 'Anna', 'status' => 'subscribed']);

    expect($index->documents)->toHaveKey((string) $contact->uuid);

    // A GDPR-törlésnek a KÜLSŐ indexbe is el kell érnie.
    app(ContactGdpr::class)->erase($contact->fresh());

    expect($index->documents)->not->toHaveKey((string) $contact->uuid);
});

it('a keresés TENANT-SZŰRT — idegen workspace kontaktja nem találat', function () {
    // Egy közös index tenant-szűrő nélkül a legrosszabb fajta szivárgás: a felület
    // kereső-mezőjébe „gmail" beírásával bárki idegen ügyfél címlistáját látja.
    //
    // A szűrő a KONTRAKTUS része — a `search()` első paramétere a workspace —, tehát nem
    // lehet elfelejteni egy hívási helyen. Ezért nem a Scout `search()`-e.
    $index = new FakeContactIndex;
    $this->app->instance(ContactSearchIndex::class, $index);

    $mienk = Contact::create(['email' => 'mienk@pelda.hu', 'status' => 'subscribed']);

    $idegen = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($idegen);
    Contact::create(['email' => 'idegen@pelda.hu', 'status' => 'subscribed']);

    app(WorkspaceContext::class)->set($this->workspace);

    $hits = $index->search((int) $this->workspace->getKey(), 'pelda.hu');

    expect($hits)->toBe([(string) $mienk->uuid]);
    // …és az indexben MINDKETTŐ ott van: a szűrés nem azért működik, mert nem indexeltünk.
    expect($index->documents)->toHaveCount(2);
});
