<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Jobs\InspectContactImport;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ContactImportProcessor;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportMappingBuilder;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Audience\Services\Import\InspectGate;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\RedactingFailedJobProvider;
use App\Support\RedactingHorizonJobRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\JobRepository;

uses(RefreshDatabase::class);

/* v1.1 · 6. közönség-import — 5. kör (m3-review5.md): bájt-nehéz sharedStrings (OOM), temp, redakció. */

function r5Zip(array $entries): string
{
    $path = sys_get_temp_dir().'/vm-r5-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

function r5Base(array $extra): array
{
    return [
        '[Content_Types].xml' => '<Types/>',
        'xl/workbook.xml' => '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData></worksheet>',
    ] + $extra;
}

beforeEach(function () {
    Storage::fake('local');
    $this->ws = Workspace::factory()->create();
    $this->user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid', 'sso_subject' => 'r5-'.uniqid(), 'name' => 'Szabó Bence',
        'email' => 'r5-'.uniqid().'@pelda.hu', 'locale' => 'hu',
    ]);
    $this->ws->members()->create(['user_id' => $this->user->getKey(), 'roles' => ['owner']]);
});

/* ---------------------------------------------------------------- B4 (a) bájt-plafon */

it('a bájt-nehéz, kevés `<`-t tartalmazó sharedStrings a bájt-plafonon elbukik (alacsony konfigurált plafonnal)', function () {
    config()->set('audience.import.xlsx_max_shared_strings_bytes', 100_000);

    // 3 csomópont, de 300 KB szöveg — a csomópont-plafon nem fogná meg.
    $path = r5Zip(r5Base(['xl/sharedStrings.xml' => '<sst><si><t>'.str_repeat('a ', 150_000).'</t></si></sst>']));

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
});

it('a többi nem-munkalap bejegyzésnek kisebb bájt-plafonja van', function () {
    config()->set('audience.import.xlsx_max_meta_bytes', 50_000);

    $path = r5Zip(r5Base(['xl/media/x.bin' => str_repeat('x', 200_000)]));

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
});

it('a leghosszabb `<`-mentes szakasz (szövegcsomópont) 4 × a cella-hossz plafon', function () {
    config()->set('audience.import.max_cell_length', 100);

    $path = r5Zip(r5Base(['xl/sharedStrings.xml' => '<sst><si><t>'.str_repeat('b', 5000).'</t></si></sst>']));

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
});

it('a hosszú szövegcsomópont a chunkhatáron átnyúlva is megfogott', function () {
    config()->set('audience.import.max_cell_length', 10_000);

    // 100 KB-nyi `<`-mentes szöveg: több 64 KB-os chunkon át.
    $path = r5Zip(r5Base(['xl/sharedStrings.xml' => '<sst><si><t>'.str_repeat('c', 100_000).'</t></si></sst>']));

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
});

it('a szokásos XLSX átmegy az új plafonokon', function () {
    $path = r5Zip(r5Base(['xl/sharedStrings.xml' => '<sst><si><t>Anna</t></si><si><t>Béla</t></si></sst>']));

    app(ImportFileInspector::class)->guard($path, 'xlsx');

    expect(true)->toBeTrue();
});

/* ---------------------------------------------------------------- B4 (b) temp könyvtár */

it('az openspout ideiglenes könyvtára köteg-szintű, a job végén törlődik', function () {
    $uploads = app(ImportUploads::class);
    $dir = $uploads->tempDir('abc');

    expect($dir)->toContain('import-tmp/abc')->and(is_dir($dir))->toBeTrue();

    file_put_contents($dir.'/x', 'x');
    $uploads->cleanTemp('abc');

    expect(is_dir($dir))->toBeFalse();
});

it('a felmérő job lefutása után nem marad ideiglenes könyvtár', function () {
    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->ws->uuid])
        ->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\na@pelda.hu\n")])->assertRedirect();

    expect(Storage::disk('local')->directories('import-tmp'))->toBe([]);
});

it('a purge az 1 óránál régebbi import-tmp könyvtárat söpri, a frisset nem', function () {
    $uploads = app(ImportUploads::class);
    $old = $uploads->tempDir('regi');
    $uploads->tempDir('uj');
    file_put_contents($old.'/f', 'x');
    touch($old.'/f', now()->subHours(3)->timestamp);
    touch($old, now()->subHours(3)->timestamp);

    $this->artisan('audience:purge-imports')->assertSuccessful();

    expect(Storage::disk('local')->exists('import-tmp/regi'))->toBeFalse()
        ->and(Storage::disk('local')->directories('import-tmp'))->toContain('import-tmp/uj');
});

/* ---------------------------------------------------------------- SHOULD-5 release */

it('a kapu-hely törölt workspace-nél is szabadul (failed és handle)', function () {
    $gate = app(InspectGate::class);
    expect($gate->acquire(9999, 55))->toBeTrue();

    (new InspectContactImport(9999, 'nincs-ilyen', null, 55))->failed(new RuntimeException('x'));

    expect($gate->acquire(9999, 55))->toBeTrue();

    $gate->release(9999, 55);
    expect($gate->acquire(9999, 55))->toBeTrue();
    $gate->release(9999, 55);

    $job = new InspectContactImport(9999, 'nincs-ilyen', null, 56);
    $gate->acquire(9999, 56);
    $job->handle(
        app(WorkspaceContext::class),
        app(ImportFileInspector::class),
        app(ImportUploads::class),
        app(ImportMappingBuilder::class),
        app(ContactImportProcessor::class),
        $gate,
    );

    expect($gate->acquire(9999, 56))->toBeTrue();
});

/* ---------------------------------------------------------------- SHOULD-2 failed-job dekorátor */

it('a failed_jobs provider redaktálja a QueryException-t, a többit érintetlenül hagyja', function () {
    $captured = null;
    $inner = new class($captured) implements FailedJobProviderInterface
    {
        public function __construct(public &$captured) {}

        public function log($connection, $queue, $payload, $exception)
        {
            $this->captured = $exception;
        }

        public function ids($queue = null)
        {
            return [];
        }

        public function all()
        {
            return [];
        }

        public function find($id)
        {
            return null;
        }

        public function forget($id)
        {
            return true;
        }

        public function flush($hours = null) {}
    };

    $provider = new RedactingFailedJobProvider($inner);

    $provider->log('redis', 'imports', '{}', new QueryException('pgsql', 'insert', ['carol@example.hu'], new PDOException("ERROR: invalid input: \"carol@example.hu\"\nDETAIL: carol@example.hu")));

    expect((string) $captured)->not->toContain('carol@');

    $plain = new RuntimeException('semmi személyes');
    $provider->log('redis', 'imports', '{}', $plain);

    expect($captured)->toBe($plain);
});

it('a failer és a Horizon job-tároló a redaktáló változat', function () {
    expect(app('queue.failer'))->toBeInstanceOf(RedactingFailedJobProvider::class)
        ->and(app(JobRepository::class))->toBeInstanceOf(RedactingHorizonJobRepository::class);
});

/* ---------------------------------------------------------------- S3 UI-oldali állapot */

it('a köteg állapota tartalmazza az updatedAt-et (az elakadt felmérés üzenetéhez)', function () {
    $import = app(WorkspaceContext::class)->runFor($this->ws, fn () => ContactImport::create(['source_type' => 'csv', 'status' => 'inspecting', 'profile' => []]));

    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->ws->uuid])
        ->get("/audience/import/{$import->uuid}")
        ->assertInertia(fn ($page) => $page->where('import.status', 'inspecting')->has('import.updatedAt'));
});
