<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | v1.1 · 6. közönség-import — TELJESÍTMÉNY: 300 000 soros CSV, streamelve, memória-plafon alatt.
 |
 | A fájlt a teszt GENERÁLJA egy ideiglenes könyvtárba (nem commitolt fixture), és a TELJES
 | folyamaton átmegy: feltöltés (felmérés) → leképezés → beállítások → előnézet → futás.
 | A queue a tesztben `sync`, tehát a job ugyanabban a processzben fut — a memóriamérés ezért
 | a feldolgozó VALÓDI csúcsát méri.
 |
 | `heavy` csoport: a futás percekig tart (a mérés a teszt végén a STDERR-re kerül), ezért a
 | napi párhuzamos futásból kizárt (`phpunit.xml`), a CI viszont külön lépésben MINDIG futtatja
 | (`php artisan test --group=heavy`). Lokálisan: `php artisan test --group=heavy`.
 */

const PERF_ROWS = 300_000;

it('a 300 000 soros CSV-t streamelve, korlátos memóriával importálja', function () {
    Storage::fake('local');

    $workspace = Workspace::factory()->create();
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'perf-'.uniqid(),
        'name' => 'Teljesítmény',
        'email' => 'perf-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);
    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => ['owner']]);

    // --- a fájl generálása ideiglenes könyvtárba ---------------------------------------
    $dir = sys_get_temp_dir().'/vm-perf-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir.'/nagy.csv';

    $handle = fopen($path, 'wb');
    fputcsv($handle, ['email', 'first_name', 'last_name', 'city'], ',', '"', '');

    $expectedErrors = 0;
    $expectedDuplicates = 0;

    for ($i = 1; $i <= PERF_ROWS; $i++) {
        $email = "tomeges{$i}@nagylista.example";

        if ($i % 1000 === 0) {
            $email = "hibas-cim-{$i}";            // hibás
            $expectedErrors++;
        } elseif ($i % 997 === 0) {
            $email = 'TOMEGES'.($i - 1).'@nagylista.example'; // az előző sor duplikátuma (nagybetűvel)
            $expectedDuplicates++;
        }

        fputcsv($handle, [$email, "Név{$i}", 'Teszt', 'Budapest'], ',', '"', '');
    }

    fclose($handle);

    $page = fn () => test()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid]);

    // A memória-csúcsot a feltöltéstől a befejezésig mérjük (a fájl generálása kimarad).
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage();
    $startedAt = microtime(true);

    $page()->post('/audience/import', ['mode' => 'file', 'file' => new UploadedFile($path, 'nagy.csv', 'text/csv', null, true)])
        ->assertRedirect();

    $import = app(WorkspaceContext::class)->runFor($workspace, fn () => ContactImport::query()->firstOrFail());

    expect($import->profile['rows'])->toBe(PERF_ROWS);

    $page()->post("/audience/import/{$import->uuid}/mapping", ['mapping' => $import->mapping, 'hasHeader' => true])->assertSessionHasNoErrors();
    $page()->post("/audience/import/{$import->uuid}/settings", [
        'listUuid' => null, 'tags' => ['nagy-import'], 'updateMode' => 'skip',
        'legalBasis' => 'verified_consent', 'consentSource' => 'Teljesítmény-teszt', 'consentDate' => '2025-03-18',
        'rolePolicy' => 'skip', 'disposablePolicy' => 'skip',
    ])->assertSessionHasNoErrors();

    $page()->post("/audience/import/{$import->uuid}/start")->assertRedirect();

    $elapsed = microtime(true) - $startedAt;
    $peakDelta = memory_get_peak_usage() - $baseline;

    fwrite(STDERR, sprintf("\n[import 300k] %.1f s, memória-csúcs: +%.1f MB\n", $elapsed, $peakDelta / 1048576));

    $import = app(WorkspaceContext::class)->runFor($workspace, fn () => $import->fresh());

    expect($import->status)->toBe('completed')
        ->and($import->processed_rows)->toBe(PERF_ROWS)
        ->and($import->error_count)->toBe($expectedErrors)
        ->and($import->stats['duplicate'])->toBe($expectedDuplicates)
        ->and($import->new_count)->toBe(PERF_ROWS - $expectedErrors - $expectedDuplicates);

    app(WorkspaceContext::class)->runFor($workspace, function () use ($import) {
        expect(Contact::query()->count())->toBe($import->new_count)
            ->and(ConsentEvent::query()->where('type', 'imported')->count())->toBe($import->new_count);
    });

    // A memória NEM nő a sorok számával: 128 MB alatt marad 300 000 sornál is.
    expect($peakDelta)->toBeLessThan(128 * 1024 * 1024);

    unlink($path);
    rmdir($dir);
})->group('heavy');
