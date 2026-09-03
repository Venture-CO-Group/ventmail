<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
 | A3 · 25–26. kritérium — RETENCIÓ és tag-statisztika.
 |
 | `DatabaseMigrations`, nem `RefreshDatabase`: a folytonos aggregátum frissítése
 | (`refresh_continuous_aggregate`) NEM futhat tranzakción belül, a `RefreshDatabase` viszont
 | pont tranzakcióba csomagolja a tesztet. A lassabb, de valódi migráció az ára annak, hogy
 | ezt a kritériumot a VALÓDI Timescale-viselkedésen mérjük, ne mockon.
 */

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function sendAggregated(array $overrides = []): Message
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['cimzett@example.test'],
        'html' => '<p>Szia</p>',
        'tags' => ['szamla'],
        'idempotency_key' => 'a-'.bin2hex(random_bytes(5)),
    ], $overrides))->message->fresh();
}

it('a nyers esemény törlődik, az AGGREGÁTUM viszont megmarad', function () {
    // Ez a kritérium lényege: a 90 napos retenció nem viheti el a kampány-statisztikát.
    // Ha a tag-arányokat futásidőben számolnánk a nyers sorokból, a 90. napon minden
    // korábbi eredmény eltűnne.
    $message = sendAggregated();

    // Az aggregátum anyagosítása (a folytonos aggregátum policy órákként fut; itt kézzel).
    DB::statement("call refresh_continuous_aggregate('message_event_daily', null, null)");

    $aggregateBefore = DB::table('message_event_daily')->where('workspace_id', $this->workspace->getKey())->sum('total');

    expect($aggregateBefore)->toBeGreaterThan(0);
    expect(MessageEvent::count())->toBeGreaterThan(0);

    // A nyers sorok törlése (a retenciós policy ezt teszi chunk-szinten).
    DB::statement('delete from message_events');

    $aggregateAfter = DB::table('message_event_daily')->where('workspace_id', $this->workspace->getKey())->sum('total');

    expect(MessageEvent::count())->toBe(0);
    expect($aggregateAfter)->toBe($aggregateBefore);
});

it('a tag-statisztika bontása helyes', function () {
    sendAggregated(['tags' => ['szamla']]);
    sendAggregated(['tags' => ['hirlevel']]);
    sendAggregated(['tags' => ['szamla', 'hirlevel']]);

    DB::statement("call refresh_continuous_aggregate('message_event_daily', null, null)");

    $perTag = DB::table('message_event_daily')
        ->where('workspace_id', $this->workspace->getKey())
        ->where('type', 'delivered')
        ->pluck('total', 'tag');

    // A `szamla` két levélen, a `hirlevel` szintén kettőn szerepel (egy levélen mindkettő).
    expect((int) $perTag['szamla'])->toBe(2);
    expect((int) $perTag['hirlevel'])->toBe(2);
});

/*
 | A tag-statisztika SZOLGÁLTATÁSON keresztül (26. kritérium).
 |
 | A nyers `DB::table('message_event_daily')` lekérdezés önmagában nem bizonyít semmit: a
 | nézeten NINCS (nem is lehet) global scope, tehát egyetlen elfelejtett `where` cross-tenant
 | számot adna. Ezért a szűrés a `TagStatistics`-be van bezárva — és ITT azt mérjük, hogy
 | tényleg be van zárva.
 */

it('a tag-statisztika arányokat számol a kézbesítéshez viszonyítva', function () {
    $message = sendAggregated(['tags' => ['szamla']]);

    // Nyitás és kattintás ugyanarra a levélre.
    foreach (['opened', 'clicked'] as $type) {
        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => $type,
            'recipient' => 'cimzett@example.test',
            'channel' => 'transactional',
            'tags' => ['szamla'],
        ]);
    }

    DB::statement("call refresh_continuous_aggregate('message_event_daily', null, null)");

    $sorok = collect(app(TagStatistics::class)->forRange(now()->subDays(7), now()))
        ->keyBy('tag');

    expect((int) $sorok['szamla']['delivered'])->toBe(1);
    expect((int) $sorok['szamla']['opened'])->toBe(1);
    expect((int) $sorok['szamla']['clicked'])->toBe(1);
    expect($sorok['szamla']['open_rate'])->toBe(100.0);
    expect($sorok['szamla']['click_rate'])->toBe(100.0);
    expect($sorok['szamla']['bounce_rate'])->toBe(0.0);
});

it('a tag-statisztika NEM lát át a másik tenanthoz', function () {
    sendAggregated(['tags' => ['szamla']]);

    $masik = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($masik);
    Domain::create(['name' => 'masik.hu', 'status' => 'verified', 'verified_at' => now()]);

    sendAggregated(['from_email' => 'no-reply@masik.hu', 'tags' => ['idegen-tag']]);

    DB::statement("call refresh_continuous_aggregate('message_event_daily', null, null)");

    // A MÁSIK tenant nézőpontjából csak a sajátja látszik.
    $masikSorai = collect(app(TagStatistics::class)->forRange(now()->subDays(7), now()))->pluck('tag');

    expect($masikSorai)->toContain('idegen-tag');
    expect($masikSorai)->not->toContain('szamla');

    // …és fordítva.
    app(WorkspaceContext::class)->set($this->workspace);

    $sajatSorai = collect(app(TagStatistics::class)->forRange(now()->subDays(7), now()))->pluck('tag');

    expect($sajatSorai)->toContain('szamla');
    expect($sajatSorai)->not->toContain('idegen-tag');
});

it('kontextus NÉLKÜL kivételt dob, nem a teljes platform adatát adja', function () {
    // Fail-closed: a „nincs kontextus → nincs szűrő" viselkedés itt minden ügyfél adatát
    // egyetlen képernyőre öntené.
    sendAggregated();

    app(WorkspaceContext::class)->forget();

    expect(fn () => app(TagStatistics::class)->forRange(now()->subDays(7), now()))
        ->toThrow(RuntimeException::class);
});

it('a statisztika a NYERS esemény törlése után is megmarad', function () {
    // Ez a 25. és a 26. kritérium TALÁLKOZÁSA: a retenció a nyers sort viszi, a
    // tag-statisztikának viszont maradnia kell — különben a 90. napon minden korábbi
    // kampány eredménye eltűnne a felületről.
    sendAggregated(['tags' => ['szamla']]);

    DB::statement("call refresh_continuous_aggregate('message_event_daily', null, null)");

    $elotte = app(TagStatistics::class)->forRange(now()->subDays(7), now());

    DB::statement('delete from message_events');

    expect(MessageEvent::count())->toBe(0);
    expect(app(TagStatistics::class)->forRange(now()->subDays(7), now()))->toBe($elotte);
});
