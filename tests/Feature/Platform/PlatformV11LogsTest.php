<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Logs\Services\LogExport;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · 7. modul — napló: Message-ID szűrő, idővonal, CSV-export (D173).
 */

beforeEach(function () {
    $this->withoutVite();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->user = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $this->user->id, 'roles' => [Role::Developer->value]]);

    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid]);
});

function v11Log(array $overrides = []): Message
{
    app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['anna.kovacs@example.test'],
        'html' => '<p>Szia</p>',
        'tags' => ['szamla'],
        'idempotency_key' => 'v11-'.bin2hex(random_bytes(5)),
    ], $overrides));

    return Message::query()->orderByDesc('id')->firstOrFail();
}

function exportCsv(string $query = ''): string
{
    $response = test()->get('/logs/export'.($query !== '' ? '?'.$query : ''));
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    return $response->streamedContent();
}

it('a Message-ID szűrő uuid-ra és <uuid@host> fejléc-alakra is talál, szemétre üres (nem 500)', function () {
    $message = v11Log();
    v11Log(['to' => ['masik@example.test']]);

    foreach ([$message->uuid, "<{$message->uuid}@mail.pelda.hu>", strtoupper((string) $message->uuid)] as $id) {
        $this->get('/logs?message_id='.urlencode((string) $id))
            ->assertInertia(fn ($page) => $page->has('messages.items', 1)->where('messages.items.0.uuid', $message->uuid));
    }

    $this->get('/logs?message_id=nem-uuid')->assertOk()->assertInertia(fn ($page) => $page->has('messages.items', 0));
    // Érvénytelen dátum sem ad adatbázis-hibát.
    $this->get('/logs?since=tegnap-delutan')->assertOk()->assertInertia(fn ($page) => $page->has('messages.items', 2));
});

it('a trace idővonala időrendben fűzi össze a láncot', function () {
    $message = v11Log();

    $this->get('/logs/'.$message->uuid)
        ->assertInertia(fn ($page) => $page
            ->component('Logs/Trace')
            ->where('trace.timeline', fn ($items) => collect($items)->pluck('kind')->contains('accepted')
                && collect($items)->pluck('at')->filter()->values()->all() === collect($items)->pluck('at')->filter()->sort()->values()->all())
        );
});

it('az export alapból maszkolja a címzettet és kihagyja a tárgyat; kérésre teljes; auditált', function () {
    v11Log(['subject' => 'Titkos tárgy']);

    $csv = exportCsv();

    expect($csv)->toContain('a***@example.test')
        ->not->toContain('anna.kovacs@example.test')
        ->not->toContain('Titkos tárgy');

    $full = exportCsv('pii=1');

    expect($full)->toContain('anna.kovacs@example.test')->toContain('Titkos tárgy');

    $audits = AuditLog::query()->withoutGlobalScopes()->where('action', 'logs.exported')->get();

    expect($audits)->toHaveCount(2)
        ->and((bool) $audits->last()->context['pii'])->toBeTrue();
});

it('az export CSV-injekció ellen védett, szűr, és csak a saját tenantot adja', function () {
    v11Log(['tags' => ['=HYPERLINK("x")'], 'to' => ['egy@example.test']]);
    v11Log(['tags' => ['hirlevel'], 'to' => ['ketto@example.test']]);

    $idegen = Workspace::factory()->create();
    app(WorkspaceContext::class)->runFor($idegen, function (): void {
        Domain::create(['name' => 'idegen.hu', 'status' => 'verified', 'verified_at' => now()]);
        v11Log(['from_email' => 'x@idegen.hu', 'to' => ['idegen@example.test']]);
    });
    app(WorkspaceContext::class)->set($this->workspace);

    $csv = exportCsv('pii=1');

    expect($csv)->toContain("'=HYPERLINK")
        ->not->toContain('idegen@example.test');

    $filtered = exportCsv('pii=1&tag=hirlevel');

    expect($filtered)->toContain('ketto@example.test')->not->toContain('egy@example.test');
});

it('az export korlátos (csonkolás-jelzéssel) és a retenció előtti adatot nem adja', function () {
    config()->set('logs.export_max_rows', 2);

    $old = v11Log(['to' => ['regi@example.test']]);
    Message::query()->whereKey($old->getKey())->update(['created_at' => now()->subDays(200)]);

    foreach (range(1, 3) as $i) {
        v11Log(['to' => ["uj{$i}@example.test"]]);
    }

    $csv = exportCsv('pii=1&since=2000-01-01');
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(4) // fejléc + 2 sor + csonkolás-jelzés
        ->and(end($lines))->toContain('# truncated')
        ->and($csv)->not->toContain('regi@example.test');

    expect(LogExport::mask('anna@pelda.hu'))->toBe('a***@pelda.hu')
        ->and(LogExport::mask(''))->toBe('');
});

it('az export percenként fékezett, és logs.view nélkül tiltott', function () {
    config()->set('logs.export_per_minute', 2);

    exportCsv();
    exportCsv();

    $this->from('/logs')->get('/logs/export')->assertRedirect('/logs')->assertSessionHas('error');

    $author = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $author->id, 'roles' => [Role::Author->value]]);

    $this->actingAs($author)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/logs/export')->assertForbidden();
});
