<?php

declare(strict_types=1);

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | v1.1 · Az import-fájlok MEGŐRZÉSI ideje: a feltöltött fájl személyes adatok tömege, a
 | félbehagyott varázsló fájlja és a lejárt hibariport nem maradhat örökre a lemezen.
 */

beforeEach(function () {
    Storage::fake('local');
    config()->set('audience.import.retention_days', 7);

    $this->workspace = Workspace::factory()->create();
});

function purgeImport(Workspace $workspace, array $attributes, int $ageDays): ContactImport
{
    return app(WorkspaceContext::class)->runFor($workspace, function () use ($attributes, $ageDays) {
        $import = ContactImport::create($attributes + ['source_type' => 'csv', 'status' => 'draft', 'profile' => [], 'mapping' => []]);
        ContactImport::query()->whereKey($import->getKey())->update(['created_at' => now()->subDays($ageDays), 'updated_at' => now()->subDays($ageDays)]);

        return $import->fresh();
    });
}

it('a lejárt, félbehagyott draft rekordját és fájlját törli', function () {
    Storage::disk('local')->put('imports/1/regi.csv', "email\na@pelda.hu\n");
    Storage::disk('local')->put('imports/1/uj.csv', "email\nb@pelda.hu\n");

    $old = purgeImport($this->workspace, ['storage_path' => 'imports/1/regi.csv'], 9);
    $fresh = purgeImport($this->workspace, ['storage_path' => 'imports/1/uj.csv'], 2);

    $this->artisan('audience:purge-imports')->assertSuccessful();

    expect(Storage::disk('local')->exists('imports/1/regi.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/1/uj.csv'))->toBeTrue();

    app(WorkspaceContext::class)->runFor($this->workspace, function () use ($old, $fresh) {
        expect(ContactImport::query()->where('uuid', $old->uuid)->exists())->toBeFalse()
            ->and(ContactImport::query()->where('uuid', $fresh->uuid)->exists())->toBeTrue();
    });
});

it('a lezárt import rekordja megmarad (audit), de a lejárt hibariport törlődik', function () {
    Storage::disk('local')->put('imports/1/jelentes.csv', "line,email\n2,rossz\n");

    $import = purgeImport($this->workspace, [
        'status' => 'completed', 'error_report_path' => 'imports/1/jelentes.csv', 'legal_basis' => 'unknown',
    ], 10);

    $this->artisan('audience:purge-imports')->assertSuccessful();

    expect(Storage::disk('local')->exists('imports/1/jelentes.csv'))->toBeFalse();

    app(WorkspaceContext::class)->runFor($this->workspace, function () use ($import) {
        $kept = ContactImport::query()->where('uuid', $import->uuid)->firstOrFail();

        expect($kept->error_report_path)->toBeNull()->and($kept->legal_basis)->toBe('unknown');
    });
});

it('a futó import fájlját nem bántja, bármilyen régi', function () {
    Storage::disk('local')->put('imports/1/fut.csv', "email\na@pelda.hu\n");

    purgeImport($this->workspace, ['status' => 'processing', 'storage_path' => 'imports/1/fut.csv'], 30);

    $this->artisan('audience:purge-imports')->assertSuccessful();

    expect(Storage::disk('local')->exists('imports/1/fut.csv'))->toBeTrue();
});
