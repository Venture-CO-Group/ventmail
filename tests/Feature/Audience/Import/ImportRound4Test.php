<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Jobs\InspectContactImport;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\InspectGate;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\RedactQueryException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/* v1.1 · 6. közönség-import — 4. kör (m3-review4.md): queue-DoS és PII a naplóban/failed_jobs-ban. */

function r4Zip(array $entries): string
{
    $path = sys_get_temp_dir().'/vm-r4-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

function r4Base(array $extra): array
{
    return [
        '[Content_Types].xml' => '<Types/>',
        'xl/workbook.xml' => '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.bin"/></Relationships>',
    ] + $extra;
}

beforeEach(function () {
    Storage::fake('local');
    $this->ws = Workspace::factory()->create();
    $this->user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid', 'sso_subject' => 'r4-'.uniqid(), 'name' => 'Szabó Bence',
        'email' => 'r4-'.uniqid().'@pelda.hu', 'locale' => 'hu',
    ]);
    $this->ws->members()->create(['user_id' => $this->user->getKey(), 'roles' => ['owner']]);
});

function r4Post($test, string $url, array $data = [])
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->ws->uuid])->post($url, $data);
}

function r4In($test, Closure $c): mixed
{
    return app(WorkspaceContext::class)->runFor($test->ws, $c);
}

/* ---------------------------------------------------------------- B2: név-független őr */

/**
 * ÉRVÉNYES munkafüzet, amelyben a `$name` bejegyzés a rels szerint VALÓBAN a sharedStrings (vagy maga
 * a munkalap) — így a teszt a PLAFONON bukik, nem egy hiányzó munkalapon (review r9 S4).
 */
function r4Flood(string $name, string $content): array
{
    if ($name === 'xl/worksheets/sheet1.bin') {
        return r4Base([$name => $content]);
    }

    return array_merge(r4Base(['xl/worksheets/sheet1.bin' => '<worksheet><sheetData><row r="1"><c><v>1</v></c></row></sheetData></worksheet>']), [
        'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.bin"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="'.substr($name, 3).'"/></Relationships>',
        $name => $content,
    ]);
}

it('a `.bin` / nagybetűs kiterjesztésű sharedStrings- és munkalap-áradatot is megfogja a guard — a PLAFONON', function (string $name) {
    config()->set('audience.import.xlsx_max_meta_nodes', 1000);
    config()->set('audience.import.xlsx_max_part_nodes', 1000);
    // A `worksheets/sheet1.bin` a rels szerint a MUNKALAP (review r8 S1: szerep, nem név) — rá a
    // munkalap-plafon (az első adatsor előtti csomópontok) vonatkozik.
    config()->set('audience.import.xlsx_max_nodes_before_data', 1000);

    $path = r4Zip(r4Flood($name, '<sst>'.str_repeat('<si/>', 20_000).'</sst>'));

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('zip'));
})->with(['xl/sharedStrings.bin', 'xl/SharedStrings.XML', 'xl/worksheets/sheet1.bin', 'xl/anything.dat']);

it('a plafon ALATTI, de drága sharedStrings a (alacsony) konfigurált plafonnal gyorsan elbukik — a PLAFONON', function () {
    config()->set('audience.import.xlsx_max_part_nodes', 5000);

    $path = r4Zip(r4Flood('xl/sharedStrings.xml', '<sst>'.str_repeat('<si><t>x</t></si>', 10_000).'</sst>'));
    $started = microtime(true);

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('zip'))
        ->and(microtime(true) - $started)->toBeLessThan(3);
});

it('a sharedStrings alapértelmezett plafonja a mért drága határ (8 M csomópont) alatt van', function () {
    expect((int) config('audience.import.xlsx_max_part_nodes'))->toBeLessThanOrEqual(8_000_000);
});

/* ---------------------------------------------------------------- B2: queue + kapu */

it('a felmérés külön queue-n fut, és van hozzá Horizon supervisor', function () {
    $job = new InspectContactImport($this->ws->id, 'x', null, 1);

    expect($job->queue)->toBe('imports-inspect');

    $supervisors = config('horizon.environments.imports');

    expect($supervisors)->toHaveKey('imports-inspect')
        ->and($supervisors['imports-inspect']['queue'])->toBe(['imports-inspect'])
        ->and($supervisors['imports-inspect']['timeout'])->toBe(60)
        ->and($supervisors['imports']['queue'])->not->toContain('imports-inspect');

    // A felmérés queue-kapcsolata rövid retry_after-ű (fatalban meghalt job ne maradjon reserved).
    expect($supervisors['imports-inspect']['connection'])->toBe('redis_imports_inspect')
        ->and(config('queue.connections.redis_imports_inspect.retry_after'))->toBeLessThanOrEqual(90);

    foreach (['local', 'testing', 'imports'] as $env) {
        expect(config("horizon.environments.{$env}"))->toHaveKey('imports-inspect');
    }
});

it('a felmérés-kapu: workspace-, user- és globális plafon, felszabadítás', function () {
    config()->set('audience.import.inspect_max_global', 2);
    $gate = app(InspectGate::class);
    $other = Workspace::factory()->create();

    expect($gate->acquire($this->ws->id, 10))->toBeTrue()
        ->and($gate->acquire($this->ws->id, 11))->toBeFalse()        // workspace-plafon (1)
        ->and($gate->acquire($other->id, 10))->toBeFalse()           // user-plafon (1)
        ->and($gate->acquire($other->id, 12))->toBeTrue()            // másik ws + user
        ->and($gate->acquire(Workspace::factory()->create()->id, 13))->toBeFalse(); // globális (2)

    $gate->release($this->ws->id, 10);

    expect($gate->acquire(Workspace::factory()->create()->id, 14))->toBeTrue();
});

it('a feltöltés után a kapu szabadul (a job a végén felszabadítja)', function () {
    r4Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\na@pelda.hu\n")])->assertRedirect();

    // A sync job lefutott és felszabadított: a következő feltöltés újra mehet.
    r4Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('g.csv', "email\nb@pelda.hu\n")])->assertSessionHasNoErrors();
});

it('a munkalap-váltás ugyanazt a kaput használja', function () {
    r4Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\na@pelda.hu\n")]);
    $import = r4In($this, fn () => ContactImport::query()->firstOrFail());
    r4In($this, fn () => ContactImport::query()->whereKey($import->id)->update(['profile' => json_encode(['format' => 'xlsx', 'sheets' => ['A', 'B']])]));

    expect(app(InspectGate::class)->acquire($this->ws->id, null))->toBeTrue();

    r4Post($this, "/audience/import/{$import->uuid}/sheet", ['sheet' => 'B'])->assertSessionHasErrors('sheet');
    expect(r4In($this, fn () => $import->fresh()->status))->toBe('draft');
});

it('az elakadt (5 percnél régebbi) inspecting megszakítható, a friss nem', function () {
    $fresh = r4In($this, fn () => ContactImport::create(['source_type' => 'csv', 'status' => 'inspecting', 'profile' => []]));
    $stale = r4In($this, fn () => ContactImport::create(['source_type' => 'csv', 'status' => 'inspecting', 'profile' => []]));
    r4In($this, fn () => ContactImport::query()->whereKey($stale->id)->update(['updated_at' => now()->subMinutes(10)]));

    r4Post($this, "/audience/import/{$fresh->uuid}/cancel")->assertStatus(409);
    r4Post($this, "/audience/import/{$stale->uuid}/cancel")->assertRedirect('/audience');
});

it('a purge az 1 óránál régebbi inspecting rekordot és fájlját törli', function () {
    Storage::disk('local')->put('imports/1/stuck.csv', "email\n");
    $stuck = r4In($this, fn () => ContactImport::create(['source_type' => 'csv', 'status' => 'inspecting', 'storage_path' => 'imports/1/stuck.csv', 'profile' => []]));
    r4In($this, fn () => ContactImport::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHours(2)]));

    $this->artisan('audience:purge-imports')->assertSuccessful();

    expect(r4In($this, fn () => ContactImport::query()->whereKey($stuck->id)->exists()))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/1/stuck.csv'))->toBeFalse();
});

/* ---------------------------------------------------------------- B3: PII */

it('a NUL-bájtos CSV (a fájl közepén) kódolási hiba: se napló, se failed_jobs nem tartalmaz címet', function () {
    Log::spy();

    // A kérés-ellenőrzés csak az első 8 KB-ot nézi: a NUL a fájl közepén van.
    $csv = "email,name\n".str_repeat("kitolto@pelda.hu,Kitoltő\n", 400)."alice@example.hu,Ba\0d\n";

    r4Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', $csv)])->assertRedirect();

    $import = r4In($this, fn () => ContactImport::query()->firstOrFail());

    expect($import->status)->toBe('failed')->and($import->failure_reason)->toBe('encoding')->and($import->storage_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([])
        ->and(json_encode([$import->profile, $import->stats]))->not->toContain('alice@');

    expect(DB::table('failed_jobs')->count())->toBe(0);

    Log::shouldNotHaveReceived('error', fn ($m, $c = []) => str_contains(json_encode($c), 'alice@'));
});

it('az UTF-16 BOM-os CSV a kérésben kódolási hibával elutasítva', function () {
    $utf16 = "\xFF\xFE".mb_convert_encoding("email\na@pelda.hu\n", 'UTF-16LE', 'UTF-8');

    r4Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', $utf16)])->assertSessionHasErrors('file');
});

it('a felmérő job mentési hibája (QueryException a mintával) abandon + redaktált napló, failed_jobs nélkül', function () {
    Log::spy();

    $armed = true;
    DB::beforeExecuting(function (string $query) use (&$armed) {
        if ($armed && str_contains($query, 'update "contact_imports"') && str_contains($query, '"profile"')) {
            $armed = false;

            throw new QueryException('pgsql', $query, ['alice@example.hu'], new PDOException("SQLSTATE[22P05]: ERROR: x\nDETAIL: alice@example.hu\nCONTEXT: JSON data: alice@example.hu"));
        }
    });

    r4Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\nalice@example.hu\n")])->assertRedirect();

    $import = r4In($this, fn () => ContactImport::query()->firstOrFail());

    expect($import->status)->toBe('failed')->and($import->failure_reason)->toBe('error')->and($import->storage_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => ! str_contains(json_encode($c), 'alice@') && isset($c['class']));
});

it('a globális QueryException-leképezés a previous DETAIL/CONTEXT sorait is eltávolítja, az SQLSTATE marad', function () {
    $pdo = new PDOException("SQLSTATE[22P05]: Untranslatable character: 7 ERROR:  unsupported\nDETAIL:  alice@example.hu\nCONTEXT:  JSON data, line 1: alice@example.hu");
    $original = new QueryException('pgsql', 'select ?', ['alice@example.hu'], $pdo);

    $mapped = (new RedactQueryException)($original);

    expect($mapped->getMessage())->not->toContain('alice@')
        ->and($mapped->getPrevious()->getMessage())->not->toContain('alice@')
        ->and($mapped->getBindings())->toBe(['?'])
        ->and($mapped->getMessage())->toContain('22P05');

    // Az ERROR első sora is hordozhat értéket: az üzenet teljesen eldobva.
    $first = new QueryException('pgsql', 'select ?', ['carol@example.hu'], new PDOException('SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "carol@example.hu"'));

    expect((new RedactQueryException)($first)->getMessage())->not->toContain('carol@')->toContain('22P02');
});
