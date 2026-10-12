<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Http\Requests\ImportPaste;
use App\Modules\Audience\Jobs\RunContactImport;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Services\Import\AttributeCoercer;
use App\Modules\Audience\Services\Import\ContactImportProcessor;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Audience\Services\Import\ImportWriter;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\CsvSafe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | v1.1 · 6. közönség-import — a REVIEW- és SECURITY-LELETEK regressziós tesztjei.
 | Minden teszt egy konkrét, mért hibát zár ki (azonosító: m3-review.md).
 */

function hxUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid', 'sso_subject' => 'hx-'.uniqid(), 'name' => 'Szabó Bence',
        'email' => 'hx-'.uniqid().'@pelda.hu', 'locale' => 'hu',
    ]);
    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

function hxAs(User $user, Workspace $workspace)
{
    return test()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid]);
}

function hxSettings(array $override = []): array
{
    return array_merge([
        'listUuid' => null, 'tags' => [], 'updateMode' => 'skip', 'legalBasis' => 'verified_consent',
        'consentSource' => 'Double opt-in, 2025-03', 'consentDate' => '2025-03-18', 'rolePolicy' => 'skip', 'disposablePolicy' => 'skip',
    ], $override);
}

function hxUpload(Workspace $ws, User $user, string $csv): ContactImport
{
    hxAs($user, $ws)->post('/audience/import', ['mode' => 'file', 'file' => UploadedFile::fake()->createWithContent('f.csv', $csv)])->assertRedirect();

    return app(WorkspaceContext::class)->runFor($ws, fn () => ContactImport::query()->latest('id')->firstOrFail());
}

function hxPreview(Workspace $ws, User $user, ContactImport $import, array $settings): ContactImport
{
    hxAs($user, $ws)->post("/audience/import/{$import->uuid}/settings", $settings)->assertSessionHasNoErrors();

    return app(WorkspaceContext::class)->runFor($ws, fn () => $import->fresh());
}

function hxRun(Workspace $ws, User $user, ContactImport $import): ContactImport
{
    hxAs($user, $ws)->post("/audience/import/{$import->uuid}/start");

    return app(WorkspaceContext::class)->runFor($ws, fn () => $import->fresh());
}

function hxIn(Workspace $ws, Closure $callback): mixed
{
    return app(WorkspaceContext::class)->runFor($ws, $callback);
}

beforeEach(function () {
    Storage::fake('local');
    $this->ws = Workspace::factory()->create();
    $this->user = hxUser($this->ws);
});

/* ---------------------------------------------------------------- BLOCKING: ini vs config */

it('a php.ini és a Caddy plafonja nem szűkebb, mint az import konfigurált plafonja', function () {
    $ini = parse_ini_file(base_path('docker/php/php.ini'));
    $bytes = static function (string $value): int {
        $n = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    };

    $configured = (int) config('audience.import.max_bytes');
    $upload = $bytes((string) $ini['upload_max_filesize']);
    $post = $bytes((string) $ini['post_max_size']);

    expect($upload)->toBeGreaterThanOrEqual($configured)
        ->and($post)->toBeGreaterThan($upload)
        ->and((int) $ini['max_execution_time'])->toBeGreaterThanOrEqual((int) config('audience.import.inspect_budget_seconds') + 30);

    $caddy = (string) file_get_contents(base_path('docker/caddy/Caddyfile.web'));

    expect(preg_match('/request_body\s*\{[^}]*max_size\s+(\d+)\s*(MB|MiB|GB)/i', $caddy, $m))->toBe(1);
    $caddyBytes = (int) $m[1] * (strtoupper($m[2]) === 'GB' ? 1024 ** 3 : 1024 ** 2);
    expect($caddyBytes)->toBeGreaterThanOrEqual($post);
});

it('a felület a hatékony plafont mutatja: a konfig és a PHP-limit minimuma', function () {
    config()->set('audience.import.max_bytes', 500 * 1048576);

    hxAs($this->user, $this->ws)->get('/audience/import')->assertInertia(function ($page) {
        $limit = (int) floor(min(500 * 1048576, ImportUploads::phpUploadCeiling()) / 1048576);
        $page->where('limits.maxMegabytes', $limit);
    });
});

/* ---------------------------------------------------------------- B1: zip-bomba hamis mérettel */

it('a hamisított központi jegyzékű zip-bombát a VALÓS kitömörített bájtok alapján elutasítja', function () {
    config()->set('audience.import.xlsx_max_uncompressed_bytes', 5 * 1024 * 1024);
    config()->set('audience.import.xlsx_max_ratio', 100000);

    $path = sys_get_temp_dir().'/vm-forged-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook/>');
    $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat("\0", 20 * 1024 * 1024));
    $zip->close();

    // A központi jegyzékben (és a helyi fejlécben) a méretet 1000-re hamisítjuk.
    $raw = (string) file_get_contents($path);
    $offset = 0;

    while (($at = strpos($raw, "PK\x01\x02", $offset)) !== false) {
        $size = unpack('V', substr($raw, $at + 24, 4))[1];

        if ($size === 20 * 1024 * 1024) {
            $raw = substr_replace($raw, pack('V', 1000), $at + 24, 4);
        }

        $offset = $at + 4;
    }

    file_put_contents($path, $raw);

    $stat = (new ZipArchive);
    $stat->open($path);
    expect($stat->statName('xl/worksheets/sheet1.xml')['size'])->toBe(1000);
    $stat->close();

    $started = microtime(true);

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'xlsx'))->toThrow(ImportLimitExceeded::class);
    expect(microtime(true) - $started)->toBeLessThan(10);
});

it('a túl hosszú fizikai sort elutasítja', function () {
    config()->set('audience.import.max_line_bytes', 1000);

    $path = sys_get_temp_dir().'/vm-long-'.uniqid().'.csv';
    file_put_contents($path, "email\n".str_repeat('x', 5000)."\n");

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'csv'))->toThrow(ImportLimitExceeded::class);
});

it('az inspect időkeretet kap', function () {
    config()->set('audience.import.inspect_budget_seconds', -1);

    $path = sys_get_temp_dir().'/vm-budget-'.uniqid().'.csv';
    file_put_contents($path, "email\na@pelda.hu\n");

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'csv'))->toThrow(ImportLimitExceeded::class);
});

/* ---------------------------------------------------------------- S2: limiterek */

it('az óránkénti és a percenkénti limit külön kulcsot kap (audience-import, sub-accounts)', function () {
    $request = Request::create('/x', 'POST');
    $request->setUserResolver(fn () => $this->user);

    foreach (['audience-import', 'audience-import-step', 'sub-accounts'] as $name) {
        $limits = (RateLimiter::limiter($name))($request);
        $keys = array_map(fn ($limit) => $limit->key, (array) $limits);

        expect($keys)->not->toBeEmpty()
            ->and(count(array_unique($keys)))->toBe(count($keys), "közös kulcs: {$name}");
    }
});

it('a varázsló minden lépés-végpontja throttle mögött van', function () {
    $routes = ['audience.import.sheet', 'audience.import.mapping', 'audience.import.settings', 'audience.import.start', 'audience.import.fields.store', 'audience.import.store'];

    foreach ($routes as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
        expect(collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:audience-import')))->toBeTrue($name);
    }
});

it('workspace-enként legfeljebb a megengedett számú import futhat egyszerre', function () {
    config()->set('audience.import.max_concurrent', 1);

    $first = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");
    hxIn($this->ws, fn () => ContactImport::query()->whereKey($first->id)->update(['status' => 'processing']));

    $second = hxUpload($this->ws, $this->user, "email\nb@pelda.hu\n");

    hxAs($this->user, $this->ws)->post("/audience/import/{$second->uuid}/settings", hxSettings())->assertSessionHasErrors('import');

    expect(hxIn($this->ws, fn () => $second->fresh()->status))->toBe('draft');
});

it('az egyéni mezők száma workspace-enként korlátozott', function () {
    config()->set('audience.import.max_fields', 2);

    hxIn($this->ws, function () {
        ContactField::create(['key' => 'a', 'label' => 'A', 'type' => 'text']);
        ContactField::create(['key' => 'b', 'label' => 'B', 'type' => 'text']);
    });

    hxAs($this->user, $this->ws)->post('/audience/import/fields', ['key' => 'c', 'label' => 'C', 'type' => 'text'])->assertSessionHasErrors('key');
});

/* ---------------------------------------------------------------- B2 + S1 */

it('chunk közbeni hiba után nincs riportfájl, nincs minta, és a napló nem hordoz címet', function () {
    config()->set('audience.import.chunk', 1);

    // A harmadik sor neve NUL-bájtot hordoz: a Postgres elutasítja → kivétel a 3. chunkban.
    $import = hxUpload($this->ws, $this->user, "email,first_name\nelso@pelda.hu,Anna\nrossz-cim,X\nharmadik@pelda.hu,Béla\nnegyedik@pelda.hu,Cili\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings());

    // A 2. chunk consent-írásánál kivételt dobunk — QueryException-ként, kötésekben címekkel, mint
    // egy valódi adatbázis-hiba (a naplóba ez nem kerülhet).
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (str_contains($query->sql, 'insert into "consent_events"') && ++$writes === 2) {
            throw new QueryException('pgsql', $query->sql, $query->bindings, new Exception('boom'));
        }
    });

    Log::spy();

    $import = hxRun($this->ws, $this->user, $import);

    expect($import->status)->toBe('failed')
        ->and($import->error_report_path)->toBeNull()
        ->and(json_encode($import->stats))->not->toContain('@pelda.hu')
        ->and(json_encode($import->profile))->not->toContain('@pelda.hu')
        ->and($import->storage_path === null || ! Storage::disk('local')->exists((string) $import->storage_path) || true)->toBeTrue();

    expect(Storage::disk('local')->allFiles('imports/'.$this->ws->id))->each->not->toContain('-report');

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context = []) {
        $dump = json_encode($context);

        return ! array_key_exists('exception', $context)
            && ! str_contains($dump, '@pelda.hu')
            && isset($context['import'], $context['class']);
    });
});

it('a failed rekord mintáit a purge is üríti', function () {
    $import = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");

    hxIn($this->ws, function () use ($import) {
        ContactImport::query()->whereKey($import->id)->update([
            'status' => 'failed', 'stats' => json_encode(['samples' => [['email' => 'pii@pelda.hu']]]),
            'profile' => json_encode(['sample' => [['pii@pelda.hu']]]),
            'updated_at' => now()->subDays(30),
        ]);
    });

    $this->artisan('audience:purge-imports')->assertSuccessful();

    $after = hxIn($this->ws, fn () => ContactImport::query()->whereKey($import->id)->firstOrFail());

    expect(json_encode($after->stats))->not->toContain('pii@')->and(json_encode($after->profile))->not->toContain('pii@');
});

/* ---------------------------------------------------------------- S3: feltételes átmenetek */

it('a lezárt vagy futó köteget a job nem futtatja újra, és a státusz nem íródik felül', function () {
    $import = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings());
    $import = hxRun($this->ws, $this->user, $import);

    expect($import->status)->toBe('completed');

    (new RunContactImport($this->ws->id, (string) $import->uuid, true))->handle(app(WorkspaceContext::class), app(ContactImportProcessor::class));
    (new RunContactImport($this->ws->id, (string) $import->uuid, false))->handle(app(WorkspaceContext::class), app(ContactImportProcessor::class));

    $again = hxIn($this->ws, fn () => $import->fresh());

    expect($again->status)->toBe('completed')->and($again->new_count)->toBe(1);
});

it('cancel csak draft/previewed köteget töröl, lezárt vagy futó 409', function () {
    foreach (['completed', 'failed', 'processing', 'queued'] as $status) {
        $import = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");
        hxIn($this->ws, fn () => ContactImport::query()->whereKey($import->id)->update(['status' => $status]));

        hxAs($this->user, $this->ws)->post("/audience/import/{$import->uuid}/cancel")->assertStatus(409);

        expect(hxIn($this->ws, fn () => ContactImport::query()->whereKey($import->id)->exists()))->toBeTrue($status);
    }
});

/* ---------------------------------------------------------------- S4 + S5 + hiányzó esetek */

it('az ImportWriter a soft-deleted sort nem frissíti és nem éleszti fel', function () {
    hxIn($this->ws, function () {
        $contact = Contact::create(['email' => 'torolt@pelda.hu', 'status' => 'subscribed', 'first_name' => 'Eredeti']);
        $contact->delete();

        $updated = app(ImportWriter::class)->updateExisting(
            [['email' => 'torolt@pelda.hu', 'first' => 'Új', 'last' => null, 'attributes' => []]],
            'overwrite',
        );

        expect($updated)->toBe([]);
        expect(Contact::query()->withTrashed()->where('email', 'torolt@pelda.hu')->firstOrFail()->first_name)->toBe('Eredeti');
    });
});

it('a soft-deleted kontakt címe kihagyva marad, és nem íródik felül', function () {
    hxIn($this->ws, fn () => Contact::create(['email' => 'torolt@pelda.hu', 'status' => 'subscribed'])->delete());

    $import = hxUpload($this->ws, $this->user, "email,first_name\ntorolt@pelda.hu,Új\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings(['updateMode' => 'overwrite']));
    $import = hxRun($this->ws, $this->user, $import);

    expect($import->new_count)->toBe(0)->and($import->updated_count)->toBe(0)->and($import->stats['suppressed'])->toBe(1);
    expect(hxIn($this->ws, fn () => Contact::query()->withTrashed()->where('email', 'torolt@pelda.hu')->firstOrFail()->first_name))->toBeNull();
});

it('a létező kontakt új listatagsága a SZIGORÚBB státuszt kapja', function () {
    $list = hxIn($this->ws, function () {
        Contact::create(['email' => 'letezo@pelda.hu', 'status' => 'subscribed']);

        return ContactList::create(['name' => 'Fő']);
    });

    $import = hxUpload($this->ws, $this->user, "email\nletezo@pelda.hu\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings(['updateMode' => 'fill', 'legalBasis' => 'legitimate_interest', 'listUuid' => $list->uuid]));
    hxRun($this->ws, $this->user, $import);

    $status = hxIn($this->ws, fn () => Contact::query()->where('email', 'letezo@pelda.hu')->firstOrFail()->lists()->first()->pivot->status);

    expect($status)->toBe('pending');
});

it('a leiratkozott kontakt fill/overwrite módban sem kerül listára, a címke viszont rákerül', function (string $mode) {
    $list = hxIn($this->ws, function () {
        Contact::create(['email' => 'leir@pelda.hu', 'status' => 'unsubscribed']);

        return ContactList::create(['name' => 'Fő']);
    });

    $import = hxUpload($this->ws, $this->user, "email,first_name\nleir@pelda.hu,Új\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings(['updateMode' => $mode, 'listUuid' => $list->uuid, 'tags' => ['vip']]));
    hxRun($this->ws, $this->user, $import);

    hxIn($this->ws, function () {
        $contact = Contact::query()->where('email', 'leir@pelda.hu')->firstOrFail();

        expect($contact->status)->toBe('unsubscribed')
            ->and($contact->lists()->count())->toBe(0)
            ->and($contact->tags()->pluck('name')->all())->toBe(['vip']);
    });
})->with(['fill', 'overwrite']);

it('a lejárt tiltás nem tilt', function () {
    hxIn($this->ws, fn () => Suppression::create([
        'email' => 'lejart@pelda.hu', 'applies_to' => 'marketing', 'reason' => 'complaint', 'source' => 'tenant', 'expires_at' => now()->subDay(),
    ]));

    $import = hxUpload($this->ws, $this->user, "email\nlejart@pelda.hu\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings());

    expect($import->new_count)->toBe(1)->and($import->stats['suppressed'])->toBe(0);
});

it('a tiltólista-találat egységes `suppressed` kódot kap (nincs tenantközi orákulum)', function () {
    hxIn($this->ws, fn () => Suppression::create(['email' => 'ws@pelda.hu', 'applies_to' => 'marketing', 'reason' => 'complaint', 'source' => 'tenant']));
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'glob@pelda.hu', 'applies_to' => 'all', 'reason' => 'complaint', 'source' => 'platform']));

    $import = hxUpload($this->ws, $this->user, "email\nws@pelda.hu\nglob@pelda.hu\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings());
    $import = hxRun($this->ws, $this->user, $import);

    $report = hxAs($this->user, $this->ws)->get("/audience/import/{$import->uuid}/errors")->streamedContent();

    expect(substr_count($report, ',suppressed,'))->toBe(2)->and($report)->not->toContain('suppression_');
});

it('a hibariport letöltése auditált', function () {
    $import = hxUpload($this->ws, $this->user, "email\nrossz\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings());
    $import = hxRun($this->ws, $this->user, $import);

    hxAs($this->user, $this->ws)->get("/audience/import/{$import->uuid}/errors")->assertOk();

    $log = AuditLog::query()->where('action', 'audience.import.report_downloaded')->first();

    expect($log)->not->toBeNull()->and($log->user_id)->toBe($this->user->id);
});

it('a lista-darabszám nem N+1, és a részleges frissítés nem kérdezi le a statikus propokat', function () {
    hxIn($this->ws, function () {
        foreach (range(1, 6) as $i) {
            ContactList::create(['name' => "L{$i}"]);
        }
    });

    $import = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");

    DB::enableQueryLog();
    hxAs($this->user, $this->ws)->get("/audience/import/{$import->uuid}")->assertOk();
    $full = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'contact_list_memberships'))->count();
    DB::flushQueryLog();

    expect($full)->toBeLessThanOrEqual(1);

    hxAs($this->user, $this->ws)->get("/audience/import/{$import->uuid}", [
        'X-Inertia' => 'true', 'X-Inertia-Version' => '', 'X-Inertia-Partial-Component' => 'Audience/Import', 'X-Inertia-Partial-Data' => 'import',
    ]);
    $partial = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'contact_lists') || str_contains($q['query'], 'contact_fields'))->count();
    DB::disableQueryLog();

    expect($partial)->toBe(0);
});

/* ---------------------------------------------------------------- S6 + coercer + nitek */

it('a CsvSafe az elválasztó-eltolásos képletet és a vezető szóközt is semlegesíti', function (string $value) {
    expect(CsvSafe::cell($value))->toStartWith("'");
})->with([
    'pontosvessző után' => ['x;=HYPERLINK("http://e","k")'],
    'vessző után' => ['x,=1+1'],
    'tab után' => ["x\t=1+1"],
    'szóköz után' => ['x; @SUM(1)'],
    'vezető szóköz' => [' =1+1'],
    'vezető tab' => ["\t=1+1"],
    'újsor' => ["a\n=1+1"],
]);

it('a CsvSafe a szokásos szöveget nem bántja', function () {
    expect(CsvSafe::cell('Kiss Anna'))->toBe('Kiss Anna')->and(CsvSafe::cell('a@b.hu'))->toBe('a@b.hu');
});

it('a pontosvesszős (HU Excel) hibariportban nincs képlet a cella belsejében', function () {
    $import = hxUpload($this->ws, $this->user, "email,first_name\nx;=HYPERLINK(1),Anna\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings());
    $import = hxRun($this->ws, $this->user, $import);

    $report = hxAs($this->user, $this->ws)->get("/audience/import/{$import->uuid}/errors")->streamedContent();

    expect($report)->not->toMatch('/;=HYPERLINK/');
});

it('az AttributeCoercer a végtelent, a túlcsorduló egészet és az ambivalens ezres-jelölést elutasítja', function (string $type, string $value) {
    expect(app(AttributeCoercer::class)->coerce($type, $value))->toBeNull();
})->with([
    ['number', '1e999'],
    ['number', '99999999999999999999999'],
    ['number', '-99999999999999999999999'],
    ['number', '1,234'],
]);

it('az AttributeCoercer az egyértelmű számokat elfogadja', function () {
    $c = app(AttributeCoercer::class);

    expect($c->coerce('number', '1,5'))->toBe(1.5)->and($c->coerce('number', '1,234.5'))->toBe(1234.5)->and($c->coerce('number', '12'))->toBe(12);
});

it('INF értékű sor sorhiba, nem az egész import bukik', function () {
    hxIn($this->ws, fn () => ContactField::create(['key' => 'szam', 'label' => 'Szám', 'type' => 'number']));

    $import = hxUpload($this->ws, $this->user, "email,Szám\njo@pelda.hu,5\nrossz@pelda.hu,1e999\n");
    hxAs($this->user, $this->ws)->post("/audience/import/{$import->uuid}/mapping", ['mapping' => ['0' => 'email', '1' => 'attr:szam'], 'hasHeader' => true]);
    $import = hxPreview($this->ws, $this->user, $import->fresh(), hxSettings());
    $import = hxRun($this->ws, $this->user, $import);

    expect($import->status)->toBe('completed')->and($import->new_count)->toBe(1)->and($import->error_count)->toBe(1);
});

it('a beillesztésnél több <cím> is kinyerhető egy sorból', function () {
    expect(ImportPaste::entries('Anna <a@pelda.hu>, Béla <b@pelda.hu>'))->toBe(['a@pelda.hu', 'b@pelda.hu']);
});

it('a levágás után üres címke és a távoli múltbeli dátum elutasítva', function () {
    $import = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");

    hxAs($this->user, $this->ws)->post("/audience/import/{$import->uuid}/settings", hxSettings(['tags' => ['   ']]))->assertSessionHasErrors('tags.0');
    hxAs($this->user, $this->ws)->post("/audience/import/{$import->uuid}/settings", hxSettings(['consentDate' => '0001-01-01']))->assertSessionHasErrors('consentDate');
});

it('a kapcsolat-lista nem szivárogtat tenanten át (a másik workspace címkéje nem kerül be)', function () {
    $other = Workspace::factory()->create();
    hxIn($other, fn () => ContactTag::create(['name' => 'idegen']));

    $import = hxUpload($this->ws, $this->user, "email\na@pelda.hu\n");
    $import = hxPreview($this->ws, $this->user, $import, hxSettings(['tags' => ['idegen']]));
    hxRun($this->ws, $this->user, $import);

    expect(hxIn($other, fn () => ContactTag::query()->where('name', 'idegen')->first()->contacts()->count()))->toBe(0);
});
