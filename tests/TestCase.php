<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A VITE-MANIFESZT NÉLKÜL is fusson a PHP-oldali teszt.
     *
     * ## Miért kell
     *
     * Az Inertia-oldalak teljes HTML-t renderelnek, a gyökér-blade pedig `@vite`-ot hív —
     * az pedig `public/build/manifest.json`-t követel. A manifeszt build-termék, a repóban
     * nincs benne (`.gitignore`), és a Backend CI-kapu SZÁNDÉKOSAN nem futtat npm-et: a
     * frontendnek külön kapuja van (typecheck + build + Playwright).
     *
     * Mérve: enélkül a CI-ban 11 teszt bukott el „Vite manifest not found"-dal, miközben
     * lokálisan zöldek voltak — pusztán azért, mert a fejlesztői gépen ott a `public/build`.
     * Ez a fajta eltérés a legrosszabb: a hiba nem a kódról szól, és nem is ott jelenik meg.
     *
     * ## Miért nem gyengítés
     *
     * A `withoutVite()` KIZÁRÓLAG az asset-tageket némítja el. A PHP-teszt tárgya a
     * jogosultság, az adat és a válasz-szerkezet (`assertInertia`) — hogy a `<script src>`
     * hash-e helyes-e, azt a Frontend- és az E2E-kapu méri, valódi buildből.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
