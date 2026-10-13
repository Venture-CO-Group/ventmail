<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Jobs\InspectContactImport;
use App\Modules\Audience\Jobs\RunContactImport;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | v1.1 · 6. közönség-import, D71 — a FELMÉRÉS a háttérjobban fut, a kérés csak tárol, rekordot
 | készít, olcsó őröket futtat és dispatch-el. A kérés-útvonalon az openspout NEM fut.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->ws = Workspace::factory()->create();
    $this->user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid', 'sso_subject' => 'as-'.uniqid(), 'name' => 'Szabó Bence',
        'email' => 'as-'.uniqid().'@pelda.hu', 'locale' => 'hu',
    ]);
    $this->ws->members()->create(['user_id' => $this->user->getKey(), 'roles' => ['owner']]);
});

function d71Post($test, string $url, array $data = [])
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->ws->uuid])->post($url, $data);
}

function d71In($test, Closure $c): mixed
{
    return app(WorkspaceContext::class)->runFor($test->ws, $c);
}

/**
 * ÉRVÉNYES munkafüzet (munkalap + rels), amelynek a `$role` szerepű része (sharedStrings / styles) a
 * csomópont-áradat — így a kérés a PLAFONON bukik, nem egy hiányzó munkalapon (review r9 S4).
 */
function d71Xlsx(int $nodes, string $role = 'sharedStrings'): UploadedFile
{
    $path = sys_get_temp_dir().'/vm-ss-'.uniqid().'.xlsx';
    $part = $role === 'styles' ? 'xl/styles.xml' : 'xl/sharedStrings.xml';
    $content = $role === 'styles'
        ? '<styleSheet>'.str_repeat('<xf/>', $nodes).'</styleSheet>'
        : '<sst>'.str_repeat('<si/>', $nodes).'</sst>';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$role.'" Target="'.substr($part, 3).'"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>email</t></is></c></row></sheetData></worksheet>');
    $zip->addFromString($part, $content);
    $zip->close();

    return new UploadedFile($path, 'ss.xlsx', null, null, true);
}

it('a kérés csak tárol, rekordot készít és dispatch-el: a profil üres, a státusz inspecting', function () {
    Queue::fake();

    d71Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\na@pelda.hu\n")])->assertRedirect();

    $import = d71In($this, fn () => ContactImport::query()->firstOrFail());

    expect($import->status)->toBe('inspecting')
        ->and($import->profile)->toBe(['format' => 'csv'])
        ->and($import->storage_path)->not->toBeNull();

    Queue::assertPushed(InspectContactImport::class, 1);
});

it('a felmérő job a profilt, a leképezést írja, és draftba viszi a köteget', function () {
    d71Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "E-mail cím,Keresztnév\na@pelda.hu,Anna\n")])->assertRedirect();

    $import = d71In($this, fn () => ContactImport::query()->firstOrFail());

    expect($import->status)->toBe('draft')
        ->and($import->profile['columns'])->toBe(['E-mail cím', 'Keresztnév'])
        ->and($import->mapping)->toBe(['0' => 'email', '1' => 'first_name'])
        ->and($import->total_rows)->toBe(1);
});

it('a sharedStrings-áradat a kérésben, olcsón elbukik, fájl és rekord nélkül', function () {
    config()->set('audience.import.xlsx_max_part_nodes', 1000);

    $started = microtime(true);

    // A PLAFON a hiba oka (nem egy hiányzó munkalap — review r9 S4).
    d71Post($this, '/audience/import', ['mode' => 'file', 'file' => d71Xlsx(50_000)])
        ->assertSessionHasErrors(['file' => __('ventmail.audience.import.limit.zip')]);

    expect(microtime(true) - $started)->toBeLessThan(5)
        ->and(d71In($this, fn () => ContactImport::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('a styles/workbook XML-részekre is van (kisebb) csomópont-plafon — a styles a SAJÁT, mért plafonját kapja', function () {
    config()->set('audience.import.xlsx_max_styles_nodes', 500);

    d71Post($this, '/audience/import', ['mode' => 'file', 'file' => d71Xlsx(5000, 'styles')])
        ->assertSessionHasErrors(['file' => __('ventmail.audience.import.limit.zip')]);
});

it('a worker-kill (failed) a felmérés közben is mindent töröl: fájl, minta, státusz', function () {
    Storage::disk('local')->put('imports/1/x.csv', "email\npii@pelda.hu\n");

    $import = d71In($this, fn () => ContactImport::create([
        'source_type' => 'csv', 'status' => 'inspecting', 'storage_path' => 'imports/1/x.csv', 'profile' => ['sample' => [['pii@pelda.hu']]],
    ]));

    (new InspectContactImport($this->ws->id, (string) $import->uuid))->failed(new RuntimeException('timeout'));

    $after = d71In($this, fn () => $import->fresh());

    expect($after->status)->toBe('failed')->and($after->storage_path)->toBeNull()
        ->and(json_encode($after->profile))->not->toContain('pii@')
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('a munkalap-váltás is aszinkron: inspecting, dispatch, közben a leképezés 409', function () {
    d71Post($this, '/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', "email\na@pelda.hu\n")]);
    $import = d71In($this, fn () => ContactImport::query()->firstOrFail());

    // CSV-nél nincs munkalap → a végpont hibát ad, és a státusz érintetlen marad.
    d71Post($this, "/audience/import/{$import->uuid}/sheet", ['sheet' => 'X'])->assertSessionHasErrors('sheet');
    expect(d71In($this, fn () => $import->fresh()->status))->toBe('draft');

    d71In($this, fn () => ContactImport::query()->whereKey($import->id)->update(['status' => 'inspecting']));

    d71Post($this, "/audience/import/{$import->uuid}/mapping", ['mapping' => ['0' => 'email'], 'hasHeader' => true])->assertStatus(409);
});

it('az abandon csak a sikeres feltételes átmenet UTÁN ír mezőket (lezárt köteg érintetlen)', function () {
    Storage::disk('local')->put('imports/1/y.csv', "email\n");

    $import = d71In($this, fn () => ContactImport::create([
        'source_type' => 'csv', 'status' => 'completed', 'storage_path' => 'imports/1/y.csv', 'profile' => [], 'stats' => ['new' => 5],
    ]));

    (new RunContactImport($this->ws->id, (string) $import->uuid, true))->failed(new RuntimeException('late'));

    $after = d71In($this, fn () => $import->fresh());

    expect($after->status)->toBe('completed')->and($after->failure_reason)->toBeNull()->and($after->stats)->toBe(['new' => 5]);
});
