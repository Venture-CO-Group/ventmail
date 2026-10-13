<?php

declare(strict_types=1);

use App\Modules\Analytics\Models\EngagementClientDaily;
use App\Modules\Analytics\Services\ClientStatsRecorder;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul (D229) — az eszköz/kliens-számláló a VALÓDI tracking-úton íródik, és a nyers
 | User-Agent SEHOL nem perzisztálódik (sem a táblában, sem az eseményben, sem webhookban).
 */

const VM_SECRET_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X; TitkosUA-7f3a9) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';

it('a nyitás a tracking-végponton át +1-et ír, a nyers UA sehol nem marad', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    $message = AnalyticsFixtures::trackedMessage($campaign);

    $token = app(TrackingTokens::class)->issue((int) $workspace->getKey(), (string) $message->uuid, 'nyito@pelda.hu', 'email.pelda.hu');

    $this->withHeader('User-Agent', VM_SECRET_UA)->get('https://email.pelda.hu/t/o/'.$token)->assertOk();

    $row = EngagementClientDaily::query()->sole();
    expect($row->scope)->toBe('campaign:'.$campaign->uuid)
        ->and($row->kind)->toBe('open')
        ->and($row->client)->toBe('ios_mail')
        ->and($row->device)->toBe('mobile')
        ->and($row->total)->toBe(1);

    // A NYERS UA egyetlen táblában sincs (esemény, webhook-kézbesítés, napló, analitika).
    $hits = [];
    foreach (DB::select("select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'") as $table) {
        $columns = collect(Schema::getColumns($table->table_name))
            ->filter(fn (array $c) => in_array($c['type_name'], ['text', 'varchar', 'jsonb', 'json', 'bpchar'], true))
            ->pluck('name');

        foreach ($columns as $column) {
            if (DB::table($table->table_name)->whereRaw('"'.$column.'"::text like ?', ['%TitkosUA-7f3a9%'])->exists()) {
                $hits[] = $table->table_name.'.'.$column;
            }
        }
    }

    expect($hits)->toBe([]);
});

it('idempotens összegzés: ugyanaz a nap/kategória egy sor, a számláló nő', function () {
    AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    $message = AnalyticsFixtures::trackedMessage($campaign);
    $recorder = app(ClientStatsRecorder::class);

    $recorder->record($message, 'opened', 'Mozilla/5.0');
    $recorder->record($message, 'opened', 'Mozilla/5.0');
    $recorder->record($message, 'clicked', 'Mozilla/5.0');

    expect(EngagementClientDaily::query()->count())->toBe(2)
        ->and(EngagementClientDaily::query()->where('kind', 'open')->value('total'))->toBe(2)
        ->and(EngagementClientDaily::query()->where('kind', 'open')->value('client'))->toBe('apple_mpp');
});

it('tranzakciós (nem kampány/journey) levélre nem ír', function () {
    AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    $message = AnalyticsFixtures::trackedMessage($campaign, tags: ['szamla']);

    app(ClientStatsRecorder::class)->record($message, 'opened', 'Mozilla/5.0');

    expect(EngagementClientDaily::query()->count())->toBe(0);
});

it('az ütközési cél a workspace_id-t is tartalmazza: két tenant ugyanarra a hatókörre két sor', function () {
    [$a] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    $message = AnalyticsFixtures::trackedMessage($campaign);
    app(ClientStatsRecorder::class)->record($message, 'opened', 'Mozilla/5.0');

    [$b] = AnalyticsFixtures::tenant();
    $foreign = AnalyticsFixtures::trackedMessage(AnalyticsFixtures::campaign(), tags: ['campaign:'.$campaign->uuid]);
    app(ClientStatsRecorder::class)->record($foreign, 'opened', 'Mozilla/5.0');

    expect(EngagementClientDaily::query()->count())->toBe(1);

    app(WorkspaceContext::class)->set($a);
    expect(EngagementClientDaily::query()->sole()->total)->toBe(1);
});
