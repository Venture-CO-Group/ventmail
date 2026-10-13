<?php

declare(strict_types=1);

use App\Modules\Logs\Http\Controllers\LogController;
use Illuminate\Support\Facades\Route;

/*
 | A napló és az üzenet-trace felülete. A modul-provider adja a kötelező stacket
 | (web + auth + workspace), tehát idegen tenant üzenete nem is látszik.
 */

Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
// A statisztika ELŐBB, mint a `{uuid}`: különben a „statistics" szó üzenet-azonosítónak
// látszana, és a képernyő 404-et adna.
Route::get('/logs/statistics', [LogController::class, 'statistics'])->name('logs.statistics');
// Az EXPORT is a `{uuid}` ELŐTT (ugyanaz az ok).
// POST + CSRF (N2): az export auditált, fékezett mellékhatás — GET-en egy beágyazott link is
// kiválthatná a böngészőből.
Route::post('/logs/export', [LogController::class, 'export'])->name('logs.export');
Route::get('/logs/{uuid}', [LogController::class, 'show'])->name('logs.show');

// v1.1 · 7. modul: küldési statisztika domainenként és időben (D175).
Route::get('/statistics', [LogController::class, 'domainStatistics'])->name('statistics.domains');
