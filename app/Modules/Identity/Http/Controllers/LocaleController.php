<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * NYELVVÁLASZTÁS — a felhasználó sorára, nem a session-be.
 *
 * Miért az oszlopba: a nyelv a FELHASZNÁLÓ tulajdonsága, nem a böngészőé. Session-ben
 * tárolva egy másik gépen újra angolul jelentkezne be, és a rendszer-e-mailek (amiket
 * nem böngészőből küldünk) sem tudnák, milyen nyelven írjanak neki.
 *
 * A `SetLocale` middleware innen olvas — a `Accept-Language` csak akkor dönt, ha a
 * felhasználónak nincs érvényes választása.
 */
final class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $supported = array_values((array) config('app.supported_locales', []));

        // A LISTA ZÁRT: idegen érték nem juthat a fordító-rétegbe (a Laravel a furcsa
        // értékre kivételt dob, path-szerű értékkel pedig a fájl-feloldásra hatna).
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($supported)],
        ]);

        $request->user()?->forceFill(['locale' => $validated['locale'], 'locale_source' => 'user'])->save();

        app()->setLocale($validated['locale']);

        return back()->with('success', __('ventmail.app.locale_switched'));
    }
}
