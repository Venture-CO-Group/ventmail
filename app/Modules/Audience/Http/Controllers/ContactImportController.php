<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Enums\ImportLegalBasis;
use App\Modules\Audience\Http\Requests\ImportMappingRequest;
use App\Modules\Audience\Http\Requests\ImportPaste;
use App\Modules\Audience\Http\Requests\ImportSettingsRequest;
use App\Modules\Audience\Http\Requests\ImportSheetRequest;
use App\Modules\Audience\Http\Requests\StoreContactFieldRequest;
use App\Modules\Audience\Http\Requests\UploadImportRequest;
use App\Modules\Audience\Jobs\RunContactImport;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Services\Import\ColumnSuggester;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportMappingBuilder;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A KONTAKT-IMPORT VARÁZSLÓJA (v1.1): feltöltés → oszlopok → beállítások → előnézet →
 * folyamat → eredmény.
 *
 * ## Az állapot a SZERVEREN él
 *
 * Minden lépés egy `contact_imports` sort ír, a képernyő pedig azt rajzolja ki — a
 * böngésző újratöltése, a fül bezárása vagy a háttérfeladat hossza nem veszít el semmit. A
 * folyamat-képernyő az állapotot lekérdezi (polling), a futás maga a `imports` sorban megy.
 *
 * ## Az írási művelet hármas kapuja
 *
 *  1. `audience.manage` jogosultság minden végponton;
 *  2. a jogalap kötelező (`ImportSettingsRequest`), a „subscribed" csak igazolt
 *     hozzájárulásból következik (`ImportLegalBasis::resultingStatus`);
 *  3. a futás csak ELŐNÉZET után indítható (`previewed` → `queued`, atomikusan).
 */
final class ContactImportController
{
    public function __construct(
        private readonly ImportUploads $uploads,
        private readonly ImportFileInspector $inspector,
        private readonly ImportMappingBuilder $mappings,
        private readonly AuditLogger $audit,
        private readonly WorkspaceContext $context,
    ) {}

    /** A varázsló nyitóképernyője (nincs még köteg). */
    public function create(): Response
    {
        Gate::authorize('audience.manage');

        return $this->page(null);
    }

    public function show(string $uuid): Response
    {
        Gate::authorize('audience.manage');

        return $this->page($this->find($uuid));
    }

    /** 1. lépés: fájl / beillesztett lista / kézi egyedi cím → draft köteg. */
    public function store(UploadImportRequest $request): RedirectResponse
    {
        $workspaceId = $this->context->workspaceId();
        $mode = (string) $request->input('mode');

        $name = null;
        $hash = null;
        $size = null;
        $format = 'csv';

        if ($mode === 'file') {
            $file = $request->file('file');
            $format = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
            $name = mb_substr($file->getClientOriginalName(), 0, 255);
            $hash = hash_file('sha256', (string) $file->getRealPath());
            $size = (int) $file->getSize();
            $path = $this->uploads->storeUpload($file, $workspaceId, $format);
        } elseif ($mode === 'paste') {
            $path = $this->uploads->storeText($this->csv([['email'], ...array_map(static fn (string $e): array => [$e], ImportPaste::entries((string) $request->input('emails')))]), $workspaceId);
            $size = (int) $this->uploads->disk()->size($path);
            $hash = hash('sha256', (string) $this->uploads->disk()->get($path));
        } else {
            $path = $this->uploads->storeText($this->csv([
                ['email', 'first_name', 'last_name'],
                [(string) $request->input('email'), (string) $request->input('first_name'), (string) $request->input('last_name')],
            ]), $workspaceId);
            $size = (int) $this->uploads->disk()->size($path);
            $hash = hash('sha256', (string) $this->uploads->disk()->get($path));
        }

        try {
            $profile = $this->inspector->inspect($this->uploads->absolute($path), $format);
        } catch (ImportLimitExceeded $e) {
            $this->uploads->delete($path);

            return back()->withErrors(['file' => (string) __('ventmail.audience.import.limit.'.$e->reason)]);
        }

        $import = ContactImport::create([
            'user_id' => $request->user()?->getKey(),
            'source_type' => $mode === 'file' ? $format : $mode,
            'file_name' => $name,
            'file_hash' => $hash,
            'file_size' => $size,
            'storage_path' => $path,
            'profile' => $profile,
            'mapping' => $this->mappings->initial($profile, $this->fieldList()),
            'tags' => [],
            'total_rows' => $profile['rows'],
            'status' => ContactImport::STATUS_DRAFT,
        ]);

        return redirect()->route('audience.import.show', $import->uuid);
    }

    /** XLSX: másik munkalap választása — újra felmérjük, és a leképezést újraépítjük. */
    public function sheet(ImportSheetRequest $request, string $uuid): RedirectResponse
    {
        $import = $this->lock($this->find($uuid), ContactImport::STATUS_DRAFT);
        $profile = (array) $import->profile;

        if (($profile['format'] ?? 'csv') !== 'xlsx' || ! in_array($request->input('sheet'), (array) ($profile['sheets'] ?? []), true)) {
            return back()->withErrors(['sheet' => (string) __('ventmail.audience.import.limit.sheet')]);
        }

        try {
            $fresh = $this->inspector->inspect($this->uploads->absolute((string) $import->storage_path), 'xlsx', (string) $request->input('sheet'), verifyZip: false);
        } catch (ImportLimitExceeded $e) {
            return back()->withErrors(['sheet' => (string) __('ventmail.audience.import.limit.'.$e->reason)]);
        }

        $import->update([
            'profile' => $fresh,
            'mapping' => $this->mappings->initial($fresh, $this->fieldList()),
            'total_rows' => $fresh['rows'],
            'status' => ContactImport::STATUS_DRAFT,
        ]);

        return redirect()->route('audience.import.show', $import->uuid);
    }

    /** 2. lépés: az oszlop-leképezés mentése. */
    public function mapping(ImportMappingRequest $request, string $uuid): RedirectResponse
    {
        $import = $this->lock($this->find($uuid), ContactImport::STATUS_DRAFT);
        $profile = (array) $import->profile;

        $width = (int) ($profile['width'] ?? 0);
        $mapping = [];

        foreach ((array) $request->input('mapping') as $column => $target) {
            if ((int) $column < $width) {
                $mapping[(int) $column] = (string) $target;
            }
        }

        $hasHeader = $request->boolean('hasHeader');
        $profile['hasHeader'] = $hasHeader;
        $profile['rows'] = max(0, (int) ($profile['records'] ?? 0) - ($hasHeader ? 1 : 0));

        $import->update([
            'mapping' => $mapping,
            'profile' => $profile,
            'total_rows' => $profile['rows'],
            'status' => ContactImport::STATUS_DRAFT,
        ]);

        return redirect()->route('audience.import.show', $import->uuid);
    }

    /** Egyéni mező felvétele helyben (a leképezés lépésből). */
    public function storeField(StoreContactFieldRequest $request): RedirectResponse
    {
        ContactField::create($request->safe()->only(['key', 'label', 'type']));

        return back()->with('success', __('ventmail.audience.import.fieldCreated'));
    }

    /** 3. lépés: beállítások + a jogalap; majd az előnézet háttérfeladata. */
    public function settings(ImportSettingsRequest $request, string $uuid): RedirectResponse
    {
        $import = $this->find($uuid);

        if ($this->activeRuns() >= (int) config('audience.import.max_concurrent')) {
            return back()->withErrors(['import' => (string) __('ventmail.audience.import.concurrency')]);
        }

        $import = $this->lock($import, ContactImport::STATUS_PREVIEWING);

        $basis = ImportLegalBasis::from((string) $request->input('legalBasis'));
        $source = trim((string) $request->input('consentSource', ''));
        $date = $request->input('consentDate');
        $list = $request->filled('listUuid') ? ContactList::query()->where('uuid', $request->input('listUuid'))->first() : null;

        $import->update([
            'contact_list_id' => $list?->getKey(),
            'tags' => array_values(array_unique(array_filter(array_map('trim', (array) $request->input('tags', [])), static fn (string $tag): bool => $tag !== ''))),
            'update_mode' => (string) $request->input('updateMode'),
            'legal_basis' => $basis->value,
            'consent_source' => $source === '' ? null : $source,
            'consent_date' => $date,
            'resulting_status' => $basis->resultingStatus($source, $date),
            'role_policy' => (string) $request->input('rolePolicy', 'skip'),
            'disposable_policy' => (string) $request->input('disposablePolicy', 'skip'),
            'phase' => null,
        ]);

        RunContactImport::dispatch($this->context->workspaceId(), (string) $import->uuid, false);

        return redirect()->route('audience.import.show', $import->uuid);
    }

    /** 5. lépés: a futás indítása — csak előnézet után, és csak egyszer. */
    public function start(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $import = $this->find($uuid);

        if ($import->status === ContactImport::STATUS_PREVIEWED && $this->activeRuns() >= (int) config('audience.import.max_concurrent')) {
            return back()->withErrors(['import' => (string) __('ventmail.audience.import.concurrency')]);
        }

        // ATOMI ÁTVITEL: az `UPDATE … WHERE status = previewed` pontosan egy kérésnek ad 1-et.
        // A dupla kattintás, az újratöltött POST és a párhuzamos fül a másodiknál 0-t kap, és
        // nem indít második futást.
        $claimed = ContactImport::query()
            ->whereKey($import->getKey())
            ->where('status', ContactImport::STATUS_PREVIEWED)
            ->update(['status' => ContactImport::STATUS_QUEUED, 'updated_at' => now()]);

        if ($claimed === 1) {
            $this->audit->log('audience.import.started', $import, context: [
                'import' => (string) $import->uuid,
                'file_name' => $import->file_name,
                'source_type' => $import->source_type,
                'legal_basis' => $import->legal_basis,
                'consent_source' => $import->consent_source,
                'consent_date' => $import->consent_date?->toDateString(),
                'resulting_status' => $import->resulting_status,
                'update_mode' => $import->update_mode,
                'list' => $import->contact_list_id,
                'rows' => $import->total_rows,
            ]);

            // Az import NEM küld levelet; a re-permission kampányt a tenant indítja a PreSendGate-en át.
            RunContactImport::dispatch($this->context->workspaceId(), (string) $import->uuid, true);
        }

        return redirect()->route('audience.import.show', $import->uuid);
    }

    /** A hibariport CSV letöltése. */
    public function errors(string $uuid): StreamedResponse
    {
        Gate::authorize('audience.manage');

        $import = $this->find($uuid);

        abort_unless($this->uploads->exists($import->error_report_path), 404);

        // A riport a feltöltő fájl címeit és neveit hordozza: a letöltés auditált.
        $this->audit->log('audience.import.report_downloaded', $import, context: [
            'import' => (string) $import->uuid,
            'rows' => (int) $import->error_count + (int) $import->skipped_count,
        ]);

        return $this->uploads->disk()->download((string) $import->error_report_path, 'import-'.$import->uuid.'-errors.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Mégse: csak a még nem futtatott (draft / previewed) köteg törölhető — a lezárt rekord az audit része. */
    public function cancel(string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $import = $this->find($uuid);

        // FELTÉTELES törlés: a futó, lezárt vagy sikertelen köteghez nem nyúl (409).
        $deleted = ContactImport::query()
            ->whereKey($import->getKey())
            ->whereIn('status', [ContactImport::STATUS_DRAFT, ContactImport::STATUS_PREVIEWED])
            ->delete();

        abort_if($deleted === 0, 409);

        $this->uploads->delete($import->storage_path);
        $this->uploads->delete($import->error_report_path);
        $this->audit->log('audience.import.cancelled', $import, context: ['import' => (string) $import->uuid, 'file_name' => $import->file_name]);

        return redirect('/audience');
    }

    private function find(string $uuid): ContactImport
    {
        // A global scope a workspace-re szűr: idegen köteg uuid-ja 404.
        return ContactImport::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * FELTÉTELES zárolás: a `draft`/`previewed` köteget egyetlen `UPDATE … WHERE status IN (…)`
     * viszi a megadott állapotba. Futó, sorba állított vagy lezárt köteg (vagy egy párhuzamos kérés
     * által már átvitt) 409 — nincs olvasás-majd-írás ablak.
     */
    private function lock(ContactImport $import, string $to): ContactImport
    {
        $moved = ContactImport::query()
            ->whereKey($import->getKey())
            ->whereIn('status', [ContactImport::STATUS_DRAFT, ContactImport::STATUS_PREVIEWED])
            ->update(['status' => $to, 'updated_at' => now()]);

        abort_if($moved === 0, 409);

        return $import->fresh();
    }

    /** A workspace-ben éppen futó előnézetek és importok száma. */
    private function activeRuns(): int
    {
        return ContactImport::query()
            ->whereIn('status', [ContactImport::STATUS_PREVIEWING, ContactImport::STATUS_QUEUED, ContactImport::STATUS_PROCESSING])
            ->count();
    }

    /** @return list<array{key: string, label: string}> */
    private function fieldList(): array
    {
        return $this->fieldList ??= ContactField::query()->orderBy('label')->get(['key', 'label'])
            ->map(static fn (ContactField $field): array => ['key' => (string) $field->key, 'label' => (string) $field->label])
            ->all();
    }

    /** @var list<array{key: string, label: string}>|null */
    private ?array $fieldList = null;

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function page(?ContactImport $import): Response
    {
        // A statikus propok ZÁRLATBAN: a folyamat-képernyő másodpercenként `only: ['import']`
        // részleges frissítést kér, és ezek a lekérdezések ilyenkor ki sem értékelődnek.
        return Inertia::render('Audience/Import', [
            'import' => $import === null ? null : $this->state($import),
            'lists' => fn () => ContactList::query()->withCount('contacts')->orderBy('name')->get()->map(static fn (ContactList $list): array => [
                'uuid' => (string) $list->uuid,
                'name' => (string) $list->name,
                'contacts' => (int) $list->contacts_count,
            ])->values()->all(),
            'fields' => fn () => ContactField::query()->orderBy('label')->get()->map(static fn (ContactField $field): array => [
                'key' => (string) $field->key,
                'label' => (string) $field->label,
                'type' => (string) $field->type,
            ])->values()->all(),
            'builtinFields' => fn () => ColumnSuggester::BUILTIN_ATTRIBUTES,
            'limits' => fn () => [
                // A HATÉKONY plafon: a konfigurált és a PHP tényleges feltöltési limitjének minimuma.
                'maxMegabytes' => (int) floor(min((int) config('audience.import.max_bytes'), ImportUploads::phpUploadCeiling()) / 1048576),
                'maxRows' => (int) config('audience.import.max_rows'),
            ],
        ]);
    }

    /**
     * A köteg állapota a képernyőnek. A `profile.sample` mintasorok személyes adatok — a
     * futás után a feldolgozó törli, és itt sem kerülnek ki lezárt kötegről.
     *
     * @return array<string, mixed>
     */
    private function state(ContactImport $import): array
    {
        $profile = (array) $import->profile;
        $stats = (array) $import->stats;
        $finished = $import->finished();

        return [
            'uuid' => (string) $import->uuid,
            'status' => (string) $import->status,
            'phase' => $import->phase,
            'sourceType' => (string) $import->source_type,
            'fileName' => $import->file_name,
            'fileSize' => $import->file_size,
            'profile' => [
                'format' => $profile['format'] ?? 'csv',
                'delimiter' => $profile['delimiter'] ?? ',',
                'encoding' => $profile['encoding'] ?? 'UTF-8',
                'sheets' => $profile['sheets'] ?? [],
                'sheet' => $profile['sheet'] ?? null,
                'hasHeader' => (bool) ($profile['hasHeader'] ?? true),
                'columns' => $profile['columns'] ?? [],
                'width' => (int) ($profile['width'] ?? 0),
                'rows' => (int) ($profile['rows'] ?? 0),
            ],
            'mapping' => (object) (array) $import->mapping,
            'suggestions' => $finished ? [] : $this->mappings->suggestions($profile, $this->fieldList()),
            'settings' => [
                'listUuid' => $import->list?->uuid,
                'tags' => (array) $import->tags,
                'updateMode' => (string) $import->update_mode,
                'legalBasis' => $import->legal_basis,
                'consentSource' => $import->consent_source,
                'consentDate' => $import->consent_date?->toDateString(),
                'rolePolicy' => (string) $import->role_policy,
                'disposablePolicy' => (string) $import->disposable_policy,
                'resultingStatus' => $import->resulting_status,
            ],
            'counts' => [
                'total' => (int) $import->total_rows,
                'processed' => (int) $import->processed_rows,
                'new' => (int) $import->new_count,
                'updated' => (int) $import->updated_count,
                'skipped' => (int) $import->skipped_count,
                'errors' => (int) $import->error_count,
            ],
            'stats' => [
                'invalid' => (int) ($stats['invalid'] ?? 0),
                'duplicate' => (int) ($stats['duplicate'] ?? 0),
                'existing' => (int) ($stats['existing'] ?? 0),
                'suppressed' => (int) ($stats['suppressed'] ?? 0),
                'role' => (int) ($stats['role'] ?? 0),
                'disposable' => (int) ($stats['disposable'] ?? 0),
                'memoryMb' => (int) ($stats['memory_mb'] ?? 0),
                'elapsed' => (float) ($stats['elapsed'] ?? 0),
                'chunks' => (int) ($stats['chunks'] ?? 0),
                'samples' => array_values((array) ($stats['samples'] ?? [])),
            ],
            'chunkSize' => (int) config('audience.import.chunk'),
            'hasReport' => $import->error_report_path !== null,
            'failureReason' => $import->failure_reason,
            'startedAt' => $import->started_at?->toIso8601String(),
            'completedAt' => $import->completed_at?->toIso8601String(),
            'startedBy' => $import->user?->name,
        ];
    }
}
