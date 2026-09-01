<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 | A Feature-tesztek a Laravel TestCase-t kapják. A `RefreshDatabase` a séma-packettől
 | (M0-2) lesz alapértelmezett; addig a skeleton-tesztek nem írnak adatbázist.
 */

pest()->extend(TestCase::class)->in('Feature');

// A RefreshDatabase-t az adatbázist érintő tesztek maguk kérik (`uses(RefreshDatabase::class)`),
// hogy a séma nélküli skeleton-tesztek gyorsak maradjanak.
