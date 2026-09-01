<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 | A publikus /v1 felület az Api modul gazdája (A1). Itt csak az app-héj útvonalai élnek.
 */

Route::get('/', fn () => Inertia::render('Smoke', [
    'app' => config('app.name'),
    'stack' => ['Laravel 12', 'Vue 3 + Inertia', 'PostgreSQL 17 + TimescaleDB', 'Redis + Horizon', 'Reverb', 'Meilisearch'],
]))->name('smoke');
