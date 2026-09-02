<?php

declare(strict_types=1);

/*
 | A `phpunit.xml` deklarál egy Unit testsuite-ot; ha a könyvtár nem létezik, a paratest
 | ELHASAL, mielőtt EGYETLEN tesztet lefuttatna — a CI-ban így minden őr-teszt némán halott
 | volt (security-review B1). Ez a fájl garantálja, hogy a suite létezik és fut.
 */

it('a Unit testsuite létezik és fut', function () {
    expect(is_dir(dirname(__DIR__).'/Unit'))->toBeTrue();
});

it('a phpunit.xml minden deklarált testsuite-könyvtára létezik', function () {
    $xml = simplexml_load_file(dirname(__DIR__, 1).'/../phpunit.xml');

    foreach ($xml->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            expect(is_dir(dirname(__DIR__, 2).'/'.(string) $directory))
                ->toBeTrue('a phpunit.xml nem létező könyvtárra hivatkozik: '.(string) $directory);
        }
    }
});
