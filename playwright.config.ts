import { defineConfig, devices } from '@playwright/test';

/* A flow-kat Playwright fedi; a unit/feature réteg Pest. */
export default defineConfig({
    testDir: './e2e',

    /*
     | EGY WORKER, NEM PÁRHUZAMOS — és ez nem lustaság.
     |
     | A böngésző-suite EGYETLEN dev-munkamenetet és EGYETLEN tenantot használ (a
     | `/auth/dev-login` mindig ugyanazt a felhasználót és workspace-t adja). Párhuzamosan
     | tehát a tesztek UGYANAZOKON a sorokon dolgoznak: az egyik nyelvet vált, a másik épp
     | magyar feliratot vár; az egyik kulcsot állít ki, a másik a listát számolja. Mérve: a
     | bukások random teszteken jelentek meg, futásonként máshol — vagyis a hiba ott
     | látszott, ahol nem volt.
     |
     | A helyes alternatíva tesztenként külön tenant lenne; azt a dev-belépő ma nem tudja,
     | és amíg nem tudja, a determinizmus fontosabb, mint a néhány másodperc.
     */
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        // A dev stack Caddyje Basic Auth mögött van (golden rule 11) — APP_URL használatakor kell.
        httpCredentials:
            process.env.E2E_BASIC_AUTH_USER && process.env.E2E_BASIC_AUTH_PASS
                ? { username: process.env.E2E_BASIC_AUTH_USER, password: process.env.E2E_BASIC_AUTH_PASS }
                : undefined,
    },
    projects: [
        // A BEJELENTKEZÉS külön projekt: egyszer fut le, és a mentett munkamenetet
        // örökli minden teszt (lásd `e2e/auth.setup.ts` — a `throttle:oidc` miatt).
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState: 'e2e/.auth/state.json' },
            dependencies: ['setup'],
        },
    ],

    /* Alapból saját szervert indít (auth nélkül); a dev stack Caddyje mögé APP_URL-lel lehet mutatni. */
    webServer: process.env.APP_URL
        ? undefined
        : {
              /*
               | Az e2e-szerver nem függ Redistől/Meilitől, de a session-nek PERZISZTENSNEK
               | kell lennie: `array` driverrel a bejelentkezés nem élné túl a következő kérést.
               |
               | A `--no-reload` és a `PHP_CLI_SERVER_WORKERS` NEM optimalizálás:
               |
               |  - reload-dal az `artisan serve` FIGYELI a környezeti fájlt, és változásra
               |    ÚJRAINDÍTJA a szervert — az újraindulás ablakában a böngésző
               |    `ERR_CONNECTION_REFUSED`-öt kap, és a hiba ott látszik, ahol nincs;
               |  - workerek nélkül a beépített szerver EGYETLEN folyamat, tehát bármelyik
               |    lassú (vagy önmagára hivatkozó) kérés megállítja az összes többit.
               |
               | A KONTAKT-IMPORT háttérfeladata (`AUDIENCE_IMPORT_QUEUE_CONNECTION=database`) a
               | `database` sorra megy, és az `audience-import.spec.ts` saját `queue:work`
               | folyamatot indít rá — a 300 000 soros import így a böngészőtől függetlenül,
               | valódi queue-n fut. Minden más job továbbra is szinkron.
               |
               | A `PHP_INI_SCAN_DIR` (kezdő `:` = az alap ini-könyvtár MARAD) a feltöltési
               | plafont emeli (`e2e/php.d/e2e.ini`): a CLI-szerver alapból 8 MB-os POST-ot
               | enged, a 300 000 soros CSV ~13 MB.
               |
               | Mérve: CI-ban egy bukás után a suite maradéka mind `ERR_CONNECTION_REFUSED`
               | lett — a szerver eltűnt, miközben az alkalmazás-naplóban EGYETLEN hiba sem
               | volt. A `--no-reload` a workerek előfeltétele is (az `artisan serve`
               | különben figyelmeztet, és egy folyamattal indul).
               */
              command: 'PHP_INI_SCAN_DIR=":$PWD/e2e/php.d" SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=sync AUDIENCE_IMPORT_QUEUE_CONNECTION=database TEMPLATE_ASSET_DRIVER=local TENANCY_WORKSPACE_MAX_OWNED=1000 PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8000 --no-reload',
              url: 'http://127.0.0.1:8000/up',
              reuseExistingServer: !process.env.CI,
              timeout: 60_000,
              // A SZERVER SAJÁT SZAVA is kell a bukás mellé: a Playwright csak azt látja,
              // amit a böngésző kapott. Egy folyamat-szintű halálról (OOM, szegmentálási
              // hiba) az alkalmazás-napló nem tud — az itt jelenik meg.
              stdout: 'pipe',
              stderr: 'pipe',
          },
});
