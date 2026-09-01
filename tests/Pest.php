<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 | A Feature-tesztek a Laravel TestCase-t kapják. A `RefreshDatabase` a séma-packettől
 | (M0-2) lesz alapértelmezett; addig a skeleton-tesztek nem írnak adatbázist.
 */

pest()->extend(TestCase::class)->in('Feature');

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature/Database');
