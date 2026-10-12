<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Testing\FakeContactIndex;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Services\Metering;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

/*
 | v1.1 · 6. közönség-import — a VARÁZSLÓ végig, HTTP-n át.
 |
 | A tét: az import a legkockázatosabb írási út a rendszerben. Egy rossz lista „subscribed"-ként
 | érkezhet jogalap nélkül, egy leiratkozott cím feléledhet, egy idegen tenant fájlját meg
 | lehetne nyitni. Minden teszt egy ilyen kárt zár ki.
 */

function importUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'import-'.uniqid(),
        'name' => 'Szabó Bence',
        'email' => 'import-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

function importAs(User $user, Workspace $workspace): TestCase
{
    return test()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid]);
}

function csvUpload(string $content, string $name = 'vendegek.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/** Feltölt egy CSV-t és visszaadja a draft import-sort. */
function startImport(Workspace $workspace, User $user, string $csv, string $name = 'vendegek.csv'): ContactImport
{
    importAs($user, $workspace)
        ->post('/audience/import', ['mode' => 'file', 'file' => csvUpload($csv, $name)])
        ->assertRedirect();

    return app(WorkspaceContext::class)->runFor($workspace, fn () => ContactImport::query()->latest('id')->firstOrFail());
}

function verifiedSettings(array $override = []): array
{
    return array_merge([
        'listUuid' => null,
        'tags' => [],
        'updateMode' => 'skip',
        'legalBasis' => 'verified_consent',
        'consentSource' => 'Double opt-in a weboldalon, 2025-03',
        'consentDate' => '2025-03-18',
        'rolePolicy' => 'skip',
        'disposablePolicy' => 'skip',
    ], $override);
}

/** A varázsló lépéseit végigjárja: leképezés megerősítése, beállítások, előnézet. */
function previewImport(Workspace $workspace, User $user, ContactImport $import, array $settings): ContactImport
{
    $page = importAs($user, $workspace);

    $page->post("/audience/import/{$import->uuid}/mapping", [
        'mapping' => $import->mapping,
        'hasHeader' => $import->profile['hasHeader'],
    ])->assertSessionHasNoErrors();

    $page->post("/audience/import/{$import->uuid}/settings", $settings)->assertSessionHasNoErrors();

    return app(WorkspaceContext::class)->runFor($workspace, fn () => $import->fresh());
}

function runImport(Workspace $workspace, User $user, ContactImport $import): ContactImport
{
    importAs($user, $workspace)->post("/audience/import/{$import->uuid}/start")->assertRedirect();

    return app(WorkspaceContext::class)->runFor($workspace, fn () => $import->fresh());
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->workspace = Workspace::factory()->create();
    $this->user = importUser($this->workspace);
});

/*
 | HOZZÁFÉRÉS
 */

it('a varázsló képernyő megnyílik, de csak audience.manage joggal', function () {
    importAs($this->user, $this->workspace)->get('/audience/import')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Audience/Import')->where('import', null)->has('lists')->has('fields'));

    $analyst = importUser($this->workspace, 'analyst');

    importAs($analyst, $this->workspace)->get('/audience/import')->assertForbidden();
    importAs($analyst, $this->workspace)->post('/audience/import', ['mode' => 'paste', 'emails' => 'a@pelda.hu'])->assertForbidden();
});

/*
 | 1. FELTÖLTÉS + 2. OSZLOP-LEKÉPEZÉS
 */

it('a feltöltött CSV-t felméri, és a fejléc alapján javasol leképezést', function () {
    $csv = "E-mail cím;Vezetéknév;Keresztnév;Cég neve;Törzsvendég-kód\nkiss.anna@pelda.hu;Kiss;Anna;Duna Kft.;TV-1\n";

    $import = startImport($this->workspace, $this->user, $csv);

    expect($import->status)->toBe('draft')
        ->and($import->source_type)->toBe('csv')
        ->and($import->file_name)->toBe('vendegek.csv')
        ->and($import->file_hash)->toBe(hash('sha256', $csv))
        ->and($import->profile['delimiter'])->toBe(';')
        ->and($import->mapping)->toBe([
            '0' => 'email', '1' => 'last_name', '2' => 'first_name', '3' => 'attr:company', '4' => 'skip',
        ]);

    importAs($this->user, $this->workspace)->get("/audience/import/{$import->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Audience/Import')
            ->where('import.uuid', $import->uuid)
            ->where('import.profile.rows', 1)
            ->where('import.suggestions.0.target', 'email')
            ->where('import.suggestions.0.confidence', 100)
            ->where('import.suggestions.4.target', null)
        );
});

it('a fájl PRIVÁT lemezre kerül, nem a publikusra', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");

    expect(Storage::disk('local')->exists($import->storage_path))->toBeTrue()
        ->and($import->storage_path)->toStartWith('imports/'.$this->workspace->id.'/')
        ->and(Storage::disk('public')->exists($import->storage_path))->toBeFalse();
});

it('a feltöltés határait betartatja: méret, kiterjesztés, tartalom', function () {
    config()->set('audience.import.max_bytes', 100);

    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'file', 'file' => csvUpload("email\n".str_repeat("x@pelda.hu\n", 50))])
        ->assertSessionHasErrors('file');

    config()->set('audience.import.max_bytes', 10_000_000);

    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('k.exe', 'email')])
        ->assertSessionHasErrors('file');

    // XLSX-nek mondott, de nem zip.
    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('k.xlsx', 'ez csak szöveg')])
        ->assertSessionHasErrors('file');

    // CSV-nek mondott, de bináris.
    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('k.csv', "a\0b\0c")])
        ->assertSessionHasErrors('file');

    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactImport::query()->count()))->toBe(0);
});

it('a leképezésben pontosan egy e-mail oszlop kell, és csak ismert célok', function () {
    $import = startImport($this->workspace, $this->user, "a,b\nx@pelda.hu,Anna\n");
    $url = "/audience/import/{$import->uuid}/mapping";

    importAs($this->user, $this->workspace)->post($url, ['mapping' => ['0' => 'skip', '1' => 'first_name'], 'hasHeader' => true])
        ->assertSessionHasErrors('mapping');

    importAs($this->user, $this->workspace)->post($url, ['mapping' => ['0' => 'email', '1' => 'email'], 'hasHeader' => true])
        ->assertSessionHasErrors('mapping');

    importAs($this->user, $this->workspace)->post($url, ['mapping' => ['0' => 'email', '1' => 'attr:nincs_ilyen'], 'hasHeader' => true])
        ->assertSessionHasErrors('mapping');

    importAs($this->user, $this->workspace)->post($url, ['mapping' => ['0' => 'email', '1' => 'password'], 'hasHeader' => true])
        ->assertSessionHasErrors('mapping');
});

it('egyéni mezőt helyben lehet létrehozni, és a leképezés használhatja', function () {
    $import = startImport($this->workspace, $this->user, "email,Születésnap\na@pelda.hu,1988-04-12\n");

    importAs($this->user, $this->workspace)
        ->post('/audience/import/fields', ['key' => 'birthday', 'label' => 'Születésnap', 'type' => 'date'])
        ->assertSessionHasNoErrors();

    $field = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactField::query()->where('key', 'birthday')->firstOrFail());

    expect($field->type)->toBe('date')->and($field->label)->toBe('Születésnap');

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/mapping", ['mapping' => ['0' => 'email', '1' => 'attr:birthday'], 'hasHeader' => true])
        ->assertSessionHasNoErrors();

    // Foglalt és hibás kulcs nem megy át.
    importAs($this->user, $this->workspace)->post('/audience/import/fields', ['key' => 'email', 'label' => 'X', 'type' => 'text'])
        ->assertSessionHasErrors('key');
    importAs($this->user, $this->workspace)->post('/audience/import/fields', ['key' => 'Rossz Kulcs!', 'label' => 'X', 'type' => 'text'])
        ->assertSessionHasErrors('key');
    importAs($this->user, $this->workspace)->post('/audience/import/fields', ['key' => 'birthday', 'label' => 'Másodszor', 'type' => 'text'])
        ->assertSessionHasErrors('key');
});

/*
 | 3. BEÁLLÍTÁSOK — a jogalap KÖTELEZŐ, a „subscribed" csak igazolt hozzájárulással
 */

it('jogalap nélkül nem lehet továbblépni', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");

    $url = "/audience/import/{$import->uuid}/settings";

    importAs($this->user, $this->workspace)->post($url, verifiedSettings(['legalBasis' => null]))->assertSessionHasErrors('legalBasis');
    importAs($this->user, $this->workspace)->post($url, verifiedSettings(['legalBasis' => 'mert_igen']))->assertSessionHasErrors('legalBasis');
    importAs($this->user, $this->workspace)->post($url, verifiedSettings(['consentSource' => '']))->assertSessionHasErrors('consentSource');
    importAs($this->user, $this->workspace)->post($url, verifiedSettings(['consentDate' => null]))->assertSessionHasErrors('consentDate');
    importAs($this->user, $this->workspace)->post($url, verifiedSettings(['consentDate' => now()->addDay()->toDateString()]))->assertSessionHasErrors('consentDate');
    importAs($this->user, $this->workspace)->post($url, verifiedSettings(['updateMode' => 'törölj']))->assertSessionHasErrors('updateMode');

    expect($import->fresh()->status)->toBe('draft');
});

it('subscribed CSAK igazolt hozzájárulás + forrás + dátum esetén lesz az eredmény-státusz', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");

    $cases = [
        ['verified_consent', 'subscribed'],
        ['legitimate_interest', 'pending'],
        ['unknown', 'pending'],
    ];

    foreach ($cases as [$basis, $status]) {
        $import = previewImport($this->workspace, $this->user, $import->fresh(), verifiedSettings(['legalBasis' => $basis]));

        expect($import->resulting_status)->toBe($status, "jogalap: {$basis}");
    }
});

it('ismeretlen jogalapnál a forrás és a dátum nem kötelező, de a jogalap igen', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/settings", verifiedSettings([
            'legalBasis' => 'unknown', 'consentSource' => null, 'consentDate' => null,
        ]))
        ->assertSessionHasNoErrors();

    expect($import->fresh()->resulting_status)->toBe('pending');
});

it('a célliska és a címkék a saját workspace-hez kötöttek', function () {
    $other = Workspace::factory()->create();
    $foreignList = app(WorkspaceContext::class)->runFor($other, fn () => ContactList::create(['name' => 'Idegen']));

    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/settings", verifiedSettings(['listUuid' => $foreignList->uuid]))
        ->assertSessionHasErrors('listUuid');
});

/*
 | 4. ELŐNÉZET — a kategóriák
 */

function fixtureWorld(Workspace $workspace): void
{
    app(WorkspaceContext::class)->runFor($workspace, function () {
        // Már létező kontakt, hiányos adattal.
        Contact::create(['email' => 'letezo@pelda.hu', 'status' => 'subscribed', 'first_name' => 'Régi', 'attributes' => ['city' => 'Győr']]);
        // Leiratkozott kontakt — az import NEM élesztheti fel.
        Contact::create(['email' => 'leiratkozott@pelda.hu', 'status' => 'unsubscribed']);
        // Workspace-szintű suppression.
        Suppression::create(['email' => 'ws-tiltott@pelda.hu', 'applies_to' => 'marketing', 'reason' => 'complaint', 'source' => 'tenant']);
        // Visszavont consent kontakt nélkül.
        Consent::create(['email' => 'visszavont@pelda.hu', 'channel' => 'marketing', 'status' => 'revoked', 'revoked_at' => now()]);
        // GDPR-sírkő.
        ErasedRecipient::create(['recipient_hash' => ErasedRecipient::hash('torolt@pelda.hu'), 'erased_at' => now()]);
    });

    // Platform-szintű (globális) suppression.
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@pelda.hu', 'applies_to' => 'all', 'reason' => 'complaint', 'source' => 'platform']));
}

function mixedCsv(): string
{
    return implode("\n", [
        'email;first_name;last_name',
        'uj1@pelda.hu;Anna;Kiss',              // 2  új
        'uj2@pelda.hu;Béla;Nagy',              // 3  új
        'kiss.anna@gmail;Hibás;Cím',           // 4  hibás
        'nincs-kukac.hu;X;Y',                  // 5  hibás
        ';Üres;Cím',                           // 6  hiányzó
        'UJ1@pelda.hu;Duplikált;Sor',          // 7  duplikátum (az első sor marad)
        'letezo@pelda.hu;Új;Név',              // 8  létező
        'leiratkozott@pelda.hu;X;Y',           // 9  létező (leiratkozott)
        'ws-tiltott@pelda.hu;X;Y',             // 10 suppression (workspace)
        'globalis@pelda.hu;X;Y',               // 11 suppression (globális)
        'visszavont@pelda.hu;X;Y',             // 12 visszavont consent
        'torolt@pelda.hu;X;Y',                 // 13 GDPR-törölt
        'info@ceg.hu;;',                       // 14 szerepcím
        'teszt@mailinator.com;;',              // 15 eldobható domain
        '',
    ]);
}

it('az előnézet kategóriánként számol, és a suppression sosem „importálható"', function () {
    fixtureWorld($this->workspace);

    $import = startImport($this->workspace, $this->user, mixedCsv());
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());

    expect($import->status)->toBe('previewed');

    $stats = $import->stats;

    expect($import->total_rows)->toBe(14)
        ->and($import->new_count)->toBe(2)
        ->and($import->updated_count)->toBe(0)           // updateMode=skip → a létező kimarad
        ->and($import->error_count)->toBe(3)             // 2 hibás + 1 hiányzó
        ->and($stats['duplicate'])->toBe(1)
        ->and($stats['existing'])->toBe(2)
        ->and($stats['suppressed'])->toBe(4)             // ws + globális + visszavont + törölt
        ->and($stats['role'])->toBe(1)
        ->and($stats['disposable'])->toBe(1)
        ->and($import->skipped_count)->toBe(1 + 2 + 4 + 1 + 1);

    // Minta a problémás sorokból, sorszámmal.
    $lines = collect($stats['samples'])->pluck('line')->all();
    expect($lines)->toContain(4)->toContain(7)->toContain(10);

    // Az ELŐNÉZET nem ír kontaktot.
    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->count()))->toBe(2);
});

it('a frissítés módja szerint a létező kontaktok „frissíthetők", a szerep/eldobható engedhető', function () {
    fixtureWorld($this->workspace);

    $import = startImport($this->workspace, $this->user, mixedCsv());
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings([
        'updateMode' => 'fill', 'rolePolicy' => 'import', 'disposablePolicy' => 'import',
    ]));

    // új: uj1, uj2, info@, mailinator → 4; frissítés: letezo + leiratkozott → 2.
    expect($import->new_count)->toBe(4)
        ->and($import->updated_count)->toBe(2)
        ->and($import->stats['role'])->toBe(0)
        ->and($import->stats['disposable'])->toBe(0);
});

/*
 | 5. FUTÁS — az írás
 */

it('a futás új kontaktokat hoz létre a jogalap szerinti státusszal, és kontaktonként naplóz', function () {
    fixtureWorld($this->workspace);

    $list = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactList::create(['name' => 'Fő lista']));

    $import = startImport($this->workspace, $this->user, mixedCsv());
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings([
        'listUuid' => $list->uuid, 'tags' => ['vendégkönyv-2025', 'import-okt'],
    ]));
    $import = runImport($this->workspace, $this->user, $import);

    expect($import->status)->toBe('completed')
        ->and($import->new_count)->toBe(2)
        ->and($import->updated_count)->toBe(0)
        ->and($import->error_count)->toBe(3)
        ->and($import->skipped_count)->toBe(9)
        ->and($import->processed_rows)->toBe(14);

    app(WorkspaceContext::class)->runFor($this->workspace, function () use ($list) {
        $uj1 = Contact::query()->where('email', 'uj1@pelda.hu')->firstOrFail();

        // Igazolt hozzájárulás + forrás + dátum → subscribed.
        expect($uj1->status)->toBe('subscribed')
            ->and($uj1->first_name)->toBe('Anna')
            ->and($uj1->last_name)->toBe('Kiss')
            ->and($uj1->external_id)->toBeNull()                  // a ventOS-szinkron kulcsa érintetlen
            ->and($uj1->subscribed_at?->toDateString())->toBe('2025-03-18');

        expect($uj1->lists()->pluck('contact_lists.id')->all())->toBe([$list->id])
            ->and($uj1->tags()->pluck('name')->sort()->values()->all())->toBe(['import-okt', 'vendégkönyv-2025']);

        // A consent NEM lesz megerősített: a bizonyíték nálunk nincs, a kapu továbbra is zár.
        $consent = Consent::query()->where('email', 'uj1@pelda.hu')->where('channel', 'marketing')->firstOrFail();
        expect($consent->status)->toBe('pending')->and($consent->confirmed_at)->toBeNull();

        // KONTAKTONKÉNT egy `imported` esemény a jogalappal és a forrással.
        $events = ConsentEvent::query()->where('type', 'imported')->get();
        expect($events)->toHaveCount(2);

        $event = $events->firstWhere('email_hash', $consent->email_hash);
        expect($event->legal_basis)->toBe('consent')
            ->and($event->source)->toBe('import')
            ->and($event->form_text)->toContain('Double opt-in a weboldalon, 2025-03')
            ->and($event->payload['import'])->toBe(ContactImport::query()->firstOrFail()->uuid)
            ->and($event->payload['consent_date'])->toBe('2025-03-18')
            ->and($event->payload['resulting_status'])->toBe('subscribed');

        // Aki a tiltólistán van, SEMMIKÉPP nem lett kontakt.
        foreach (['ws-tiltott', 'globalis', 'visszavont', 'torolt'] as $local) {
            expect(Contact::query()->withTrashed()->where('email', "{$local}@pelda.hu")->exists())->toBeFalse($local);
        }

        // A leiratkozott kontakt leiratkozott maradt, és nem is frissült.
        $unsub = Contact::query()->where('email', 'leiratkozott@pelda.hu')->firstOrFail();
        expect($unsub->status)->toBe('unsubscribed');
        expect($unsub->lists()->count())->toBe(0);

        // A „kihagy" módú létező kontakt érintetlen.
        $existing = Contact::query()->where('email', 'letezo@pelda.hu')->firstOrFail();
        expect($existing->first_name)->toBe('Régi');
    });
});

it('a nem igazolt jogalapú import „pending" kontaktokat ír (re-permission szükséges)', function () {
    $import = startImport($this->workspace, $this->user, "email\nuj@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings(['legalBasis' => 'legitimate_interest']));
    runImport($this->workspace, $this->user, $import);

    app(WorkspaceContext::class)->runFor($this->workspace, function () {
        $contact = Contact::query()->where('email', 'uj@pelda.hu')->firstOrFail();
        $event = ConsentEvent::query()->where('type', 'imported')->firstOrFail();

        expect($contact->status)->toBe('pending')
            ->and($contact->subscribed_at)->toBeNull()
            ->and($event->legal_basis)->toBe('legitimate_interest')
            ->and($event->payload['resulting_status'])->toBe('pending');
    });
});

it('a „kitölti" mód csak a hiányzó adatot pótolja, a „felülír" a fájl értékeit érvényesíti', function (string $mode, string $expectedFirst, string $expectedCity) {
    app(WorkspaceContext::class)->runFor($this->workspace, function () {
        Contact::create(['email' => 'letezo@pelda.hu', 'status' => 'subscribed', 'first_name' => 'Régi', 'last_name' => null, 'attributes' => ['city' => 'Győr']]);
        ContactField::create(['key' => 'city', 'label' => 'Város', 'type' => 'text']);
    });

    $csv = "email,first_name,last_name,city\nletezo@pelda.hu,Új,Kovács,Pécs\n";

    $import = startImport($this->workspace, $this->user, $csv);

    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings(['updateMode' => $mode]));
    $import = runImport($this->workspace, $this->user, $import);

    expect($import->updated_count)->toBe(1)->and($import->new_count)->toBe(0);

    app(WorkspaceContext::class)->runFor($this->workspace, function () use ($expectedFirst, $expectedCity) {
        $contact = Contact::query()->where('email', 'letezo@pelda.hu')->firstOrFail();

        expect($contact->first_name)->toBe($expectedFirst)
            ->and($contact->last_name)->toBe('Kovács')           // a hiányzót mindkét mód pótolja
            ->and($contact->attributes['city'])->toBe($expectedCity)
            ->and($contact->status)->toBe('subscribed');         // a státuszhoz az import nem nyúl
    });
})->with([
    'kitölti' => ['fill', 'Régi', 'Győr'],
    'felülír' => ['overwrite', 'Új', 'Pécs'],
]);

it('az egyéni mező típusát ellenőrzi, és a hibás értékű sor hibariportba kerül', function () {
    app(WorkspaceContext::class)->runFor($this->workspace, function () {
        ContactField::create(['key' => 'birthday', 'label' => 'Születésnap', 'type' => 'date']);
        ContactField::create(['key' => 'visits', 'label' => 'Látogatás', 'type' => 'number']);
    });

    $csv = "email,Születésnap,Látogatás\njo@pelda.hu,1988.04.12.,12\nrossz@pelda.hu,nem-datum,3\nrossz2@pelda.hu,2000-01-01,sok\n";

    $import = startImport($this->workspace, $this->user, $csv);
    $mapping = ['0' => 'email', '1' => 'attr:birthday', '2' => 'attr:visits'];

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/mapping", ['mapping' => $mapping, 'hasHeader' => true])
        ->assertSessionHasNoErrors();
    importAs($this->user, $this->workspace)->post("/audience/import/{$import->uuid}/settings", verifiedSettings());

    $import = runImport($this->workspace, $this->user, $import->fresh());

    expect($import->new_count)->toBe(1)->and($import->error_count)->toBe(2);

    $contact = app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->where('email', 'jo@pelda.hu')->firstOrFail());

    expect($contact->attributes['birthday'])->toBe('1988-04-12')->and($contact->attributes['visits'])->toBe(12);
});

it('a futás idempotens: az újraindítás nem fut le másodszor', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\nb@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    $import = runImport($this->workspace, $this->user, $import);

    expect($import->status)->toBe('completed')->and($import->new_count)->toBe(2);

    importAs($this->user, $this->workspace)->post("/audience/import/{$import->uuid}/start");

    $again = app(WorkspaceContext::class)->runFor($this->workspace, fn () => $import->fresh());

    expect($again->new_count)->toBe(2)->and($again->updated_count)->toBe(0);
    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => ConsentEvent::query()->where('type', 'imported')->count()))->toBe(2);
});

it('előnézet nélkül nem indítható a futás', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");

    importAs($this->user, $this->workspace)->post("/audience/import/{$import->uuid}/start");

    expect($import->fresh()->status)->toBe('draft');
    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->count()))->toBe(0);
});

/*
 | 6. EREDMÉNY — hibariport, audit, takarítás
 */

it('a hibariport letölthető, és a képlet-injekciótól védett', function () {
    $csv = "email,first_name\nrossz-cim,=cmd|'/c calc'!A0\n+36301234567,Anna\njo@pelda.hu,Béla\n";

    $import = startImport($this->workspace, $this->user, $csv);
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    $import = runImport($this->workspace, $this->user, $import);

    expect($import->error_count)->toBe(2);

    $response = importAs($this->user, $this->workspace)->get("/audience/import/{$import->uuid}/errors");

    $response->assertOk()->assertDownload();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $body = $response->streamedContent();

    expect($body)->toContain('rossz-cim')
        // A KÉPLET-kezdetű cella aposztróf-prefixet kap: a táblázatkezelő szövegnek veszi.
        ->and($body)->toContain("'=cmd|")
        ->and($body)->toContain("'+36301234567")
        ->and($body)->not->toContain(',=cmd')
        ->and($body)->toContain('invalid_email');

    // A fejléc első sora ember által olvasható oszlopnevek.
    expect(strtok($body, "\n"))->toContain('line');
});

it('hibariport nélküli futásnál nincs mit letölteni', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    $import = runImport($this->workspace, $this->user, $import);

    importAs($this->user, $this->workspace)->get("/audience/import/{$import->uuid}/errors")->assertNotFound();
});

it('a feltöltött fájl a feldolgozás után törlődik, a minta-PII-vel együtt', function () {
    $import = startImport($this->workspace, $this->user, "email\npii@pelda.hu\n");
    $path = $import->storage_path;

    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $import = runImport($this->workspace, $this->user, $import);

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($import->storage_path)->toBeNull()
        ->and(json_encode($import->profile))->not->toContain('pii@pelda.hu')
        ->and(json_encode($import->stats))->not->toContain('pii@pelda.hu');
});

it('az import auditált: ki, mikor, milyen jogalappal — cím nélkül az audit-naplóban', function () {
    fixtureWorld($this->workspace);

    $import = startImport($this->workspace, $this->user, mixedCsv(), 'vendegkonyv-osz.csv');
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    runImport($this->workspace, $this->user, $import);

    $logs = AuditLog::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->get();

    $started = $logs->firstWhere('action', 'audience.import.started');
    $completed = $logs->firstWhere('action', 'audience.import.completed');

    expect($started)->not->toBeNull()
        ->and($started->user_id)->toBe($this->user->id)
        ->and($started->context['legal_basis'])->toBe('verified_consent')
        ->and($started->context['consent_source'])->toBe('Double opt-in a weboldalon, 2025-03')
        ->and($started->context)->not->toHaveKey('file_name')
        ->and($started->context['file_ext'])->toBe('csv')
        ->and($completed)->not->toBeNull()
        ->and($completed->context['new'])->toBe(2);

    // A köteg-rekord létrehozása is auditált (a modell Auditable).
    expect($logs->where('auditable_type', ContactImport::class)->where('action', 'created'))->toHaveCount(1);

    // PII az audit-naplóban SOHA: sem a minta-sorok, sem a címek.
    $dump = json_encode($logs->map->only(['before', 'after', 'context'])->all());
    expect($dump)->not->toContain('uj1@pelda.hu')->not->toContain('kiss.anna@gmail');
});

it('a contacts_peak mérés az import után a valós létszámot követi', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\nb@pelda.hu\nc@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    runImport($this->workspace, $this->user, $import);

    $peak = app(WorkspaceContext::class)->runFor($this->workspace, fn () => app(Metering::class)->current(UsageDimension::ContactsPeak));

    expect($peak)->toBe(3);
});

it('az import NEM küld levelet: nincs DOI-levél, nincs Message-sor', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\nb@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings(['legalBasis' => 'legitimate_interest']));
    runImport($this->workspace, $this->user, $import);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => Message::query()->count()))->toBe(0);
});

it('az importált kontaktok bekerülnek a keresőindexbe', function () {
    $index = new FakeContactIndex;
    app()->instance(ContactSearchIndex::class, $index);

    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\nb@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    runImport($this->workspace, $this->user, $import);

    expect($index->documents)->toHaveCount(2);
});

/*
 | A MÁSIK KÉT ÚT: kézi egyedi + beillesztett lista
 */

it('a beillesztett listából sorokat csinál: soronként, vesszővel, pontosvesszővel, <cím> alakban', function () {
    $paste = "uj1@pelda.hu\nuj2@pelda.hu, uj3@pelda.hu; uj4@pelda.hu\nKiss Anna <uj5@pelda.hu>\n\nrossz-cim\n";

    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'paste', 'emails' => $paste])
        ->assertRedirect();

    $import = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactImport::query()->firstOrFail());

    expect($import->source_type)->toBe('paste')
        ->and($import->profile['rows'])->toBe(6)
        ->and($import->mapping)->toBe(['0' => 'email']);

    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    $import = runImport($this->workspace, $this->user, $import);

    expect($import->new_count)->toBe(5)->and($import->error_count)->toBe(1);
});

it('a kézi egyedi felvitel egy soros importot csinál, ugyanazzal a jogalap-kötelezettséggel', function () {
    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'manual', 'email' => 'egyedi@pelda.hu', 'first_name' => 'Egyed', 'last_name' => 'Iván'])
        ->assertRedirect();

    $import = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactImport::query()->firstOrFail());

    expect($import->source_type)->toBe('manual');

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/settings", verifiedSettings(['legalBasis' => null]))
        ->assertSessionHasErrors('legalBasis');

    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    runImport($this->workspace, $this->user, $import);

    $contact = app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->where('email', 'egyedi@pelda.hu')->firstOrFail());

    expect($contact->first_name)->toBe('Egyed')->and($contact->last_name)->toBe('Iván')->and($contact->status)->toBe('subscribed');
});

it('a kézi felvitelhez érvényes cím kell, a beillesztéshez legalább egy sor', function () {
    importAs($this->user, $this->workspace)->post('/audience/import', ['mode' => 'manual', 'email' => 'nem-cim'])
        ->assertSessionHasErrors('email');

    importAs($this->user, $this->workspace)->post('/audience/import', ['mode' => 'paste', 'emails' => "  \n "])
        ->assertSessionHasErrors('emails');
});

/*
 | XLSX
 */

it('az XLSX útvonal: munkalap-választás, majd ugyanaz a folyamat', function () {
    $writer = new Writer;
    $path = sys_get_temp_dir().'/vm-flow-'.uniqid().'.xlsx';
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Üres');
    $writer->addRow(Row::fromValues(['semmi']));
    $writer->addNewSheetAndMakeItCurrent()->setName('Vendégek');
    $writer->addRow(Row::fromValues(['Email', 'Keresztnév']));
    $writer->addRow(Row::fromValues(['xlsx1@pelda.hu', 'Xénia']));
    $writer->addRow(Row::fromValues(['xlsx2@pelda.hu', 'Xavér']));
    $writer->close();

    importAs($this->user, $this->workspace)
        ->post('/audience/import', ['mode' => 'file', 'file' => new UploadedFile($path, 'vendegek.xlsx', null, null, true)])
        ->assertRedirect();

    $import = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactImport::query()->firstOrFail());

    expect($import->source_type)->toBe('xlsx')->and($import->profile['sheets'])->toBe(['Üres', 'Vendégek']);

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/sheet", ['sheet' => 'Vendégek'])
        ->assertSessionHasNoErrors();

    $import = app(WorkspaceContext::class)->runFor($this->workspace, fn () => $import->fresh());

    expect($import->profile['sheet'])->toBe('Vendégek')
        ->and($import->profile['rows'])->toBe(2)
        ->and($import->mapping)->toBe(['0' => 'email', '1' => 'first_name']);

    importAs($this->user, $this->workspace)
        ->post("/audience/import/{$import->uuid}/sheet", ['sheet' => 'Nincs ilyen'])
        ->assertSessionHasErrors('sheet');

    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    $import = runImport($this->workspace, $this->user, $import);

    expect($import->new_count)->toBe(2);

    $contact = app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->where('email', 'xlsx1@pelda.hu')->firstOrFail());
    expect($contact->first_name)->toBe('Xénia');
});

it('a Windows-1250 CSV a futásban is helyesen kerül be', function () {
    // n=0xE9 é, \xE1 á, \xD5 Ő.
    $csv = "email;first_name;last_name\nkovacs@pelda.hu;\xC9va;Kov\xE1cs \xD5\n";

    $import = startImport($this->workspace, $this->user, $csv);
    expect($import->profile['encoding'])->toBe('Windows-1250');

    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    runImport($this->workspace, $this->user, $import);

    $contact = app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->where('email', 'kovacs@pelda.hu')->firstOrFail());

    expect($contact->first_name)->toBe('Éva')->and($contact->last_name)->toBe('Kovács Ő');
});

/*
 | TENANT-IZOLÁCIÓ
 */

it('idegen workspace importját nem lehet se megnyitni, se indítani, se letölteni', function () {
    $import = startImport($this->workspace, $this->user, "email\nrossz\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());
    $import = runImport($this->workspace, $this->user, $import);

    $other = Workspace::factory()->create();
    $stranger = importUser($other);

    importAs($stranger, $other)->get("/audience/import/{$import->uuid}")->assertNotFound();
    importAs($stranger, $other)->get("/audience/import/{$import->uuid}/errors")->assertNotFound();
    importAs($stranger, $other)->post("/audience/import/{$import->uuid}/start")->assertNotFound();
    importAs($stranger, $other)->post("/audience/import/{$import->uuid}/settings", verifiedSettings())->assertNotFound();
    importAs($stranger, $other)->post("/audience/import/{$import->uuid}/mapping", ['mapping' => ['0' => 'email'], 'hasHeader' => true])->assertNotFound();
});

it('a másik workspace ugyanazon címe nem „létező" és nem is duplikátum', function () {
    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->runFor($other, fn () => Contact::create(['email' => 'kozos@pelda.hu', 'status' => 'subscribed']));

    $import = startImport($this->workspace, $this->user, "email\nkozos@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());

    expect($import->new_count)->toBe(1)->and($import->stats['existing'])->toBe(0);
});

it('a workspace-szintű suppression csak a saját workspace-ben tilt', function () {
    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->runFor($other, fn () => Suppression::create([
        'email' => 'masik-ws@pelda.hu', 'applies_to' => 'marketing', 'reason' => 'complaint', 'source' => 'tenant',
    ]));

    $import = startImport($this->workspace, $this->user, "email\nmasik-ws@pelda.hu\n");
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings());

    expect($import->new_count)->toBe(1)->and($import->stats['suppressed'])->toBe(0);
});

/*
 | MÉGSE + KONTAKT-ADATLAP
 */

it('a megszakított import fájlja és rekordja törlődik', function () {
    $import = startImport($this->workspace, $this->user, "email\na@pelda.hu\n");
    $path = $import->storage_path;

    importAs($this->user, $this->workspace)->post("/audience/import/{$import->uuid}/cancel")->assertRedirect('/audience');

    expect(Storage::disk('local')->exists($path))->toBeFalse();
    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactImport::query()->count()))->toBe(0);
});

it('a kontakt-adatlap megmutatja a mezőket, listákat, címkéket és az import forrását', function () {
    $list = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ContactList::create(['name' => 'Fő lista']));

    $import = startImport($this->workspace, $this->user, "email,first_name\nadatlap@pelda.hu,Adél\n", 'vendeg-2025.csv');
    $import = previewImport($this->workspace, $this->user, $import, verifiedSettings(['listUuid' => $list->uuid, 'tags' => ['vip']]));
    runImport($this->workspace, $this->user, $import);

    $contact = app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::query()->where('email', 'adatlap@pelda.hu')->firstOrFail());

    importAs($this->user, $this->workspace)->get("/audience/contacts/{$contact->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Audience/ContactShow')
            ->where('contact.email', 'adatlap@pelda.hu')
            ->where('contact.firstName', 'Adél')
            ->where('contact.status', 'subscribed')
            ->where('lists.0.name', 'Fő lista')
            ->where('tags.0', 'vip')
            ->where('consent.status', 'pending')
            ->where('history.0.type', 'imported')
            ->where('history.0.legalBasis', 'consent')
            ->where('history.0.detail', fn ($detail) => str_contains((string) $detail, 'Double opt-in a weboldalon, 2025-03'))
            ->where('history.0.importFile', 'vendeg-2025.csv')
        );

    $other = Workspace::factory()->create();

    importAs(importUser($other), $other)->get("/audience/contacts/{$contact->uuid}")->assertNotFound();
});

it('a kapcsolat-lista a kontakt adatlapjára linkel', function () {
    app(WorkspaceContext::class)->runFor($this->workspace, fn () => Contact::create(['email' => 'link@pelda.hu', 'status' => 'subscribed']));

    importAs($this->user, $this->workspace)->get('/audience')
        ->assertInertia(fn ($page) => $page->has('contacts.items.0.uuid'));
});
