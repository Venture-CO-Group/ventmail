<?php

declare(strict_types=1);

use App\Http\Middleware\LimitRequestBody;
use App\Models\User;
use App\Modules\Audience\Jobs\RunContactImport;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\AttributeCoercer;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportWriter;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

/* v1.1 · 6. közönség-import — a SECURITY ÚJRA-REVIEW (m3-review2.md) regressziós tesztjei. */

function r2User(Workspace $workspace)
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid', 'sso_subject' => 'r2-'.uniqid(), 'name' => 'Szabó Bence',
        'email' => 'r2-'.uniqid().'@pelda.hu', 'locale' => 'hu',
    ]);
    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => ['owner']]);

    return $user;
}

function r2As($user, Workspace $ws)
{
    return test()->actingAs($user)->withSession(['workspace_uuid' => $ws->uuid]);
}

function r2In(Workspace $ws, Closure $callback): mixed
{
    return app(WorkspaceContext::class)->runFor($ws, $callback);
}

/** Kézzel épített XLSX: a munkalap `sheetData`-ja `<row>` nélküli csomópontokkal teli. */
function r2FloodXlsx(int $nodes): string
{
    $path = sys_get_temp_dir().'/vm-flood-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData>'.str_repeat('<x/>', $nodes).'</sheetData></worksheet>');
    $zip->close();

    return $path;
}

beforeEach(function () {
    Storage::fake('local');
    $this->ws = Workspace::factory()->create();
    $this->user = r2User($this->ws);
});

/* ---------------------------------------------------------------- B1 maradék */

it('a <row> nélküli csomópont-áradatot az adat előtti csomópont-plafon fogja meg', function () {
    config()->set('audience.import.xlsx_max_nodes_before_data', 1000);

    $path = r2FloodXlsx(300_000);
    $started = microtime(true);

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
    expect(microtime(true) - $started)->toBeLessThan(5);
});

it('az első sor UTÁNI csomópont-áradatot is megfogja (a security-review PoC alakja)', function () {
    config()->set('audience.import.xlsx_max_nodes_before_data', 1000);

    $path = sys_get_temp_dir().'/vm-flood2-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1"><v>1</v></c></row>'.str_repeat('<x/>', 100_000).'</sheetData></worksheet>');
    $zip->close();

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
});

it('az összes csomópont plafonja is érvényesül', function () {
    config()->set('audience.import.xlsx_max_nodes', 500);

    expect(fn () => app(ImportFileInspector::class)->inspect(r2FloodXlsx(2000), 'xlsx'))->toThrow(ImportLimitExceeded::class);
});

it('a valódi XLSX felmérése a sorszámot a <row> elemekből adja, a mintát kevés sorból olvassa', function () {
    $writer = new Writer;
    $path = sys_get_temp_dir().'/vm-ok-'.uniqid().'.xlsx';
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['email', 'név']));

    foreach (range(1, 50) as $i) {
        $writer->addRow(Row::fromValues(["a{$i}@pelda.hu", "N{$i}"]));
    }

    $writer->close();

    $profile = app(ImportFileInspector::class)->inspect($path, 'xlsx');

    expect($profile['rows'])->toBe(50)->and($profile['columns'])->toBe(['email', 'név'])->and(count($profile['sample']))->toBeLessThanOrEqual(5);
});

it('workspace-enként egyszerre egy felmérés fut', function () {
    $lock = Cache::lock('import-inspect:'.$this->ws->id, 30);
    expect($lock->get())->toBeTrue();

    r2As($this->user, $this->ws)
        ->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\na@pelda.hu\n")])
        ->assertSessionHasErrors('file');

    expect(r2In($this->ws, fn () => ContactImport::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);

    $lock->release();
});

it('a /sheet végpont külön, szigorú limitert kap (5/perc)', function () {
    $middleware = Route::getRoutes()->getByName('audience.import.sheet')->gatherMiddleware();
    expect($middleware)->toContain('throttle:audience-import-sheet');

    $request = Request::create('/x', 'POST');
    $request->setUserResolver(fn () => $this->user);
    $limits = (array) (RateLimiter::limiter('audience-import-sheet'))($request);

    expect($limits[0]->maxAttempts)->toBe(5);
});

/* ---------------------------------------------------------------- B2 maradék */

it('a worker-kill (`failed()`) ugyanúgy takarít, mint a fail(): fájlok, minta, státusz', function () {
    Storage::disk('local')->put('imports/1/x.csv', "email\npii@pelda.hu\n");
    Storage::disk('local')->put('imports/1/x-report.csv', "line\n1,pii@pelda.hu\n");

    $import = r2In($this->ws, fn () => ContactImport::create([
        'source_type' => 'csv', 'status' => 'processing', 'storage_path' => 'imports/1/x.csv', 'error_report_path' => 'imports/1/x-report.csv',
        'profile' => ['sample' => [['pii@pelda.hu']]], 'stats' => ['samples' => [['email' => 'pii@pelda.hu']]],
    ]));

    (new RunContactImport($this->ws->id, (string) $import->uuid, true))->failed(new RuntimeException('killed'));

    $after = r2In($this->ws, fn () => $import->fresh());

    expect($after->status)->toBe('failed')
        ->and($after->failure_reason)->toBe('worker')
        ->and($after->storage_path)->toBeNull()
        ->and($after->error_report_path)->toBeNull()
        ->and(json_encode([$after->profile, $after->stats]))->not->toContain('pii@');
    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('a `failed()` lezárt köteget nem ír felül', function () {
    $import = r2In($this->ws, fn () => ContactImport::create(['source_type' => 'csv', 'status' => 'completed', 'profile' => [], 'stats' => ['x' => 1]]));

    (new RunContactImport($this->ws->id, (string) $import->uuid, true))->failed(new RuntimeException('late'));

    expect(r2In($this->ws, fn () => $import->fresh()->status))->toBe('completed');
});

/* ---------------------------------------------------------------- SHOULD-1 body-limit */

it('nem-import útvonalon a 16 MB feletti Content-Length 413, az import-útvonalon nem', function () {
    $big = ['CONTENT_LENGTH' => (string) (17 * 1024 * 1024)];

    $this->call('POST', '/valami/mas', [], [], [], $big)->assertStatus(413);
    $this->call('POST', '/v1/messages', [], [], [], $big + ['CONTENT_TYPE' => 'application/json'])->assertStatus(413);

    // Az import-útvonalat a middleware átengedi (a tényleges feltöltés-plafont ott a php.ini és
    // a Caddy `@import` matcher adja).
    $middleware = new LimitRequestBody;

    foreach (['audience/import', 'audience/import/'.Str::uuid().'/sheet'] as $path) {
        $request = Request::create('/'.$path, 'POST', server: $big);
        $response = $middleware->handle($request, fn () => response('átment'));

        expect($response->getContent())->toBe('átment');
    }

    // A szokásos kis kérés átmegy a korláton.
    expect($this->call('POST', '/valami/mas', [], [], [], ['CONTENT_LENGTH' => '1024'])->getStatusCode())->not->toBe(413);
});

it('a Caddy a nagy body-t csak az import útvonalon engedi, máshol 16MB, és van olvasási időkorlát', function () {
    $caddy = (string) file_get_contents(base_path('docker/caddy/Caddyfile.web'));

    expect($caddy)->toMatch('/@import\s+path\s+\/audience\/import\s+\/audience\/import\/\*/')
        ->and($caddy)->toMatch('/request_body\s+@import\s*\{[^}]*max_size\s+140MB/')
        ->and($caddy)->toMatch('/request_body\s+@other\s*\{[^}]*max_size\s+16MB/')
        ->and($caddy)->toMatch('/read_header\s+\d+s/')
        ->and($caddy)->toMatch('/read_body\s+\d+m/');
});

it('a Dockerfile telepíti a gd-t (JPEG/PNG/WebP a szerkesztő képéhez) és a zip-et (XLSX)', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect(preg_match('/install-php-extensions[^\n]*\n?[^\n]*/', $dockerfile, $m))->toBe(1);

    $line = $m[0];

    expect($line)->toContain(' gd')->toContain(' zip');
});

/* ---------------------------------------------------------------- SHOULD-2 limiterek */

it('a member-invites és a workspace-create ablakai külön kulcsot kapnak', function () {
    $request = Request::create('/x', 'POST');
    $request->setUserResolver(fn () => $this->user);

    foreach (['member-invites', 'member-roles', 'workspace-create', 'sub-accounts', 'audience-import', 'audience-import-step', 'audience-import-sheet'] as $name) {
        $keys = array_map(fn ($limit) => $limit->key, (array) (RateLimiter::limiter($name))($request));

        expect(count(array_unique($keys)))->toBe(count($keys), "közös kulcs: {$name}");
    }
});

/* ---------------------------------------------------------------- SHOULD-3 tenant-izoláció */

it('az ImportWriter többsoros frissítése a másik workspace azonos című sorát nem érinti', function () {
    $other = Workspace::factory()->create();

    foreach ([$this->ws, $other] as $workspace) {
        r2In($workspace, function () use ($workspace) {
            foreach (['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu'] as $email) {
                Contact::create(['email' => $email, 'status' => 'subscribed', 'first_name' => 'Eredeti-'.$workspace->id]);
            }
        });
    }

    $updated = r2In($this->ws, fn () => app(ImportWriter::class)->updateExisting([
        ['email' => 'a@pelda.hu', 'first' => 'Új', 'last' => null, 'attributes' => []],
        ['email' => 'b@pelda.hu', 'first' => 'Új', 'last' => null, 'attributes' => []],
        ['email' => 'c@pelda.hu', 'first' => 'Új', 'last' => null, 'attributes' => ['x' => 1]],
    ], 'overwrite'));

    expect($updated)->toHaveCount(3);

    r2In($other, function () use ($other) {
        expect(Contact::query()->pluck('first_name')->unique()->all())->toBe(['Eredeti-'.$other->id]);
    });
    r2In($this->ws, fn () => expect(Contact::query()->pluck('first_name')->unique()->all())->toBe(['Új']));
});

/* ---------------------------------------------------------------- SHOULD-4 árva fájl */

it('a purge a rekord nélküli, lejárt árva fájlt is söpri, de a hivatkozottat nem', function () {
    Storage::disk('local')->put('imports/'.$this->ws->id.'/arva.csv', "email\npii@pelda.hu\n");
    Storage::disk('local')->put('imports/'.$this->ws->id.'/uj-arva.csv', "email\nb@pelda.hu\n");
    Storage::disk('local')->put('imports/'.$this->ws->id.'/hivatkozott.csv', "email\nc@pelda.hu\n");

    r2In($this->ws, fn () => ContactImport::create([
        'source_type' => 'csv', 'status' => 'processing', 'storage_path' => 'imports/'.$this->ws->id.'/hivatkozott.csv', 'profile' => [],
    ]));

    // A régi fájl mtime-ját a múltba állítjuk (a Storage::fake valódi fájlokat ír).
    foreach (['arva.csv', 'hivatkozott.csv'] as $name) {
        touch(Storage::disk('local')->path('imports/'.$this->ws->id.'/'.$name), now()->subDays(30)->timestamp);
    }

    $this->artisan('audience:purge-imports')->assertSuccessful();

    expect(Storage::disk('local')->exists('imports/'.$this->ws->id.'/arva.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/'.$this->ws->id.'/uj-arva.csv'))->toBeTrue()
        ->and(Storage::disk('local')->exists('imports/'.$this->ws->id.'/hivatkozott.csv'))->toBeTrue();
});

it('az inspect hibája után sem marad fájl vagy rekord', function () {
    config()->set('audience.import.max_columns', 1);

    r2As($this->user, $this->ws)
        ->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "a,b,c\n1,2,3\n")])
        ->assertSessionHasErrors('file');

    expect(r2In($this->ws, fn () => ContactImport::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

/* ---------------------------------------------------------------- NIT-ek */

it('a vezető nullás egész számmezőben következetesen szám; a szöveges mező érintetlen', function () {
    $c = app(AttributeCoercer::class);

    expect($c->coerce('number', '007'))->toBe(7)
        ->and($c->coerce('number', '-0'))->toBe(0)
        ->and($c->coerce('number', '0012.5'))->toBe(12.5)
        ->and($c->coerce('text', '007'))->toBe('007');
});

it('a fájlnév nem kerül az audit-payloadokba (kiterjesztés és méret igen), és a rekord-audit sem tartalmazza', function () {
    $import = r2As($this->user, $this->ws)->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('titkos-vendeglista.csv', "email\na@pelda.hu\n")]);

    $row = r2In($this->ws, fn () => ContactImport::query()->firstOrFail());

    r2As($this->user, $this->ws)->post("/audience/import/{$row->uuid}/cancel")->assertRedirect();

    $dump = json_encode(AuditLog::query()->get(['before', 'after', 'context'])->all());

    expect($dump)->not->toContain('titkos-vendeglista')->toContain('"file_ext":"csv"');
});
