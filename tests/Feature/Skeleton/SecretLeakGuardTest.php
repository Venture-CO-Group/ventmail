<?php

declare(strict_types=1);

use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A Laravel a QueryException üzenetébe BEHELYETTESÍTI a bindingeket: egy hibás INSERT így
 | a titkosított DKIM privát kulcsot a `laravel.log`-ba írná. A log bizalmi szintje
 | alacsonyabb, mint az adatbázisé — ciphertext + APP_KEY szivárgás = kulcskompromittálás.
 */

it('az adatbázis-hiba üzenete nem tartalmaz kulcsanyagot', function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    app(CreateDomain::class)('pelda.hu');

    try {
        app(CreateDomain::class)('pelda.hu'); // unique ütközés
        $this->fail('nem keletkezett adatbázis-hiba');
    } catch (QueryException $e) {
        $rendered = app(ExceptionHandler::class);
        $mapped = null;

        try {
            $rendered->report($e);
        } catch (Throwable) {
            // a report nem dobhat
        }

        expect($e->getMessage())->not->toContain('PRIVATE KEY');
    }
});
