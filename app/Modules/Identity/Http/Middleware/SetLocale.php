<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A KÉRÉS NYELVE (C2 · 89. kritérium).
 *
 * ## Miért kell egyáltalán
 *
 * A hat nyelv kulcs-teljességét teszt őrizte, a `users.locale`-t a ventSSO claimjéből
 * validáltan írtuk — és MÉGSEM választott nyelvet senki: a review megmérte, hogy az egész
 * kódbázisban NULLA `setLocale()` hívás volt. Vagyis minden válasz a `config('app.locale')`
 * nyelvén jött, a felhasználó beállítása pedig egy oszlop volt, amit semmi nem olvasott.
 *
 * ## A SORREND, és miért ez
 *
 * 1. A BEJELENTKEZETT felhasználó `locale`-ja. Ez az EXPLICIT választás (a ventSSO-ban ő
 *    állította be), és erősebb, mint amit a böngésző hirdet.
 * 2. `Accept-Language`, a támogatott nyelvekre szűrve. A `/v1` gépi felület: a hívó itt
 *    tudja megmondani, milyen nyelven kéri a hibaüzenetet — a `code` amúgy is a szerződés
 *    (golden rule 7), a szöveg a segítség.
 * 3. Az alap (`config('app.locale')`).
 *
 * A LISTA ZÁRT: kizárólag a `app.supported_locales` értékei jöhetnek szóba. Egy szűrés
 * nélküli `setLocale($header)` idegen bemenetet engedne a fordító-rétegbe (a Laravel a
 * furcsa értékre `InvalidArgumentException`-t dob, és path-szerű értékkel a
 * fájl-feloldásra is hatna).
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_map('strval', (array) config('app.supported_locales', []));

        $locale = $this->fromUser($request, $supported)
            ?? $this->fromHeader($request, $supported);

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /** @param list<string> $supported */
    private function fromUser(Request $request, array $supported): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        /*
         | A NYERS attribútum, nem a `preferredLocale()`.
         |
         | Itt az a kérdés, hogy a felhasználó VÁLASZTOTT-e nyelvet — nem az, hogy mi
         | legyen, ha nem. A `preferredLocale()` az alapot adná vissza, az pedig mindig
         | „nyerne" az `Accept-Language` ellen: aki sosem választott nyelvet, annak a
         | böngészője hiába kér németet.
         |
         | A `getAttributes()` a szigorú mód (`preventAccessingMissingAttributes`) mellett
         | sem dob: csonka példánynál egyszerűen nincs kulcs.
         */
        $locale = strtolower(trim((string) ($user->getAttributes()['locale'] ?? '')));

        return in_array($locale, $supported, true) ? $locale : null;
    }

    /**
     * `Accept-Language` — a Symfony `getPreferredLanguage()` a támogatott listából választ,
     * tehát idegen érték nem juthat át rajta. A régió-változatot (`hu-HU`) az alapnyelvre
     * vezetjük vissza, mert a fordítás nyelvenként van, nem régiónként.
     *
     * @param  list<string>  $supported
     */
    private function fromHeader(Request $request, array $supported): ?string
    {
        if ($supported === [] || $request->header('Accept-Language') === null) {
            return null;
        }

        $preferred = $request->getPreferredLanguage($supported);

        if ($preferred === null) {
            return null;
        }

        $base = strtolower(explode('-', str_replace('_', '-', $preferred))[0]);

        return in_array($base, $supported, true) ? $base : null;
    }
}
