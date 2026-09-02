<?php

declare(strict_types=1);

/*
 | A teszt-környezet driverei nem függhetnek attól, hogy a folyamat env-jében mi van
 | (a konténerben a compose env_file valódi környezeti változóként adja át a .env-et).
 */

it('a teszt izolált drivereken fut', function () {
    expect(config('cache.default'))->toBe('array');
    expect(config('session.driver'))->toBe('array');
    expect(config('queue.default'))->toBe('sync');
});
