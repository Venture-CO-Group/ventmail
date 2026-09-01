<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 | A reviewer jogos észrevétele: a CI Timescale service-e díszlet volt, mert egyetlen
 | teszt sem nyitott DB-kapcsolatot. Ez a smoke-teszt validálja az infrát, mielőtt az
 | M0-2 séma ráépül.
 */

it('a teszt valódi PostgreSQL ellen fut', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(DB::select('select 1 as ok')[0]->ok)->toBe(1);
});

it('a PostgreSQL 17-es major verzió', function () {
    $version = DB::select('show server_version')[0]->server_version;

    expect((int) $version)->toBeGreaterThanOrEqual(17);
});

it('a TimescaleDB kiterjesztés elérhető', function () {
    $available = DB::select("select 1 as ok from pg_available_extensions where name = 'timescaledb'");

    expect($available)->not->toBeEmpty('a TimescaleDB kiterjesztés nem érhető el — rossz image?');
});
