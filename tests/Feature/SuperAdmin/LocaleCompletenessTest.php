<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | C2 · 89. kritérium — i18n HAT NYELVEN, kulcs-teljességgel.
 |
 | ## Miért TESZT, és nem futásidejű fallback
 |
 | Mert a Laravel a hiányzó kulcs helyén vagy magát a KULCSOT írja ki
 | (`ventmail.campaign.status.sent`), vagy a fallback nyelv szövegét. Mindkettő rosszabb,
 | mint egy hiba: a felhasználó vagy egy pontokkal tagolt technikai azonosítót lát, vagy
 | angol szöveget egy magyar felületen — és EZT SENKI NEM JELENTI BE. A hibát a fordító
 | nem látja (ő a saját fájlját nézi), a fejlesztő nem látja (nála angol a felület), és a
 | felhasználó azt hiszi, így kell lennie.
 |
 | Ezért a hiányzó ÉS az extra kulcs is BUKÁS. Az extra azért, mert az elgépelt kulcs
 | (`campaign.stauts.sent`) pontosan úgy néz ki, mint egy hasznos fordítás — csak soha nem
 | jelenik meg, miközben a helyes kulcs hiányzik.
 */

/** A REFERENCIA-nyelv: ebből derül ki, mely kulcsoknak kell léteznie. */
const REFERENCE_LOCALE = 'en';

/** A hat támogatott nyelv (spec: HU/EN/DE/IT/ES/RU). */
const SUPPORTED_LOCALES = ['hu', 'en', 'de', 'it', 'es', 'ru'];

/**
 * A fordítás-tömb LAPOS kulcslistája.
 *
 * A pontokkal tagolt alak azért kell, mert a hiányt a LEVÉL szintjén keressük: egy
 * `campaign` kulcs jelenléte semmit nem mond arról, hogy a `campaign.status.sent` megvan-e.
 *
 * @param  array<string, mixed>  $translations
 * @return list<string>
 */
function flatKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = array_merge($keys, flatKeys($value, $full));

            continue;
        }

        $keys[] = $full;
    }

    sort($keys);

    return $keys;
}

/** @return list<string> */
function localeFiles(): array
{
    return collect(File::files(lang_path(REFERENCE_LOCALE)))
        ->map(static fn ($file) => $file->getFilenameWithoutExtension())
        ->values()
        ->all();
}

it('MIND A HAT nyelv könyvtára létezik', function () {
    // A hiányzó könyvtár nem „még nincs lefordítva", hanem egy nyelv, amit a felület
    // felajánl, és ami a kulcsokat írja ki szöveg helyett.
    foreach (SUPPORTED_LOCALES as $locale) {
        expect(File::isDirectory(lang_path($locale)))->toBeTrue();
    }
});

it('MINDEN nyelvben MINDEN kulcs megvan — és NINCS extra', function () {
    /*
     | A hiányzó kulcs a felhasználónak látszik, a fejlesztőnek nem: nála angol a felület.
     | Az extra kulcs pedig egy csendes elgépelés — a `campaign.stauts.sent` pontosan úgy
     | néz ki, mint egy hasznos fordítás, csak soha nem jelenik meg.
     */
    $problems = [];

    foreach (localeFiles() as $file) {
        $reference = flatKeys(require lang_path(REFERENCE_LOCALE.'/'.$file.'.php'));

        foreach (SUPPORTED_LOCALES as $locale) {
            if ($locale === REFERENCE_LOCALE) {
                continue;
            }

            $path = lang_path($locale.'/'.$file.'.php');

            if (! File::exists($path)) {
                $problems[] = "{$locale}/{$file}.php: a FÁJL hiányzik";

                continue;
            }

            $keys = flatKeys(require $path);

            foreach (array_diff($reference, $keys) as $missing) {
                $problems[] = "{$locale}/{$file}.php: HIÁNYZIK `{$missing}`";
            }

            foreach (array_diff($keys, $reference) as $extra) {
                $problems[] = "{$locale}/{$file}.php: EXTRA (elgépelt?) `{$extra}`";
            }
        }
    }

    expect($problems)->toBeEmpty('i18n kulcs-hiba:'.PHP_EOL.implode(PHP_EOL, $problems));
});

it('egyetlen fordítás sem ÜRES', function () {
    // Az üres sztring átmegy a kulcs-teljességi ellenőrzésen, a felületen viszont
    // SEMMI nem jelenik meg — egy hiányzó kulcsnál is rosszabb, mert ott legalább a kulcs
    // látszik, és valaki bejelenti.
    $empty = [];

    foreach (SUPPORTED_LOCALES as $locale) {
        foreach (localeFiles() as $file) {
            $path = lang_path($locale.'/'.$file.'.php');

            if (! File::exists($path)) {
                continue;
            }

            $translations = require $path;

            foreach (flatKeys($translations) as $key) {
                if (trim((string) data_get($translations, $key)) === '') {
                    $empty[] = "{$locale}/{$file}.php: ÜRES `{$key}`";
                }
            }
        }
    }

    expect($empty)->toBeEmpty('üres fordítás:'.PHP_EOL.implode(PHP_EOL, $empty));
});

it('a NEM ANGOL nyelvek valóban le vannak fordítva, nem az angol MÁSOLATAI', function () {
    /*
     | A „lefordítottam" leggyakoribb alakja: a fájl létezik, a kulcsok megvannak, és a
     | szövegek ANGOLUL vannak benne. A kulcs-teljességi teszt ezt átengedi.
     |
     | A mérés durva, de működik: ha egy nyelv szövegeinek TÖBB MINT a fele bitre azonos az
     | angollal, az nem fordítás. A rövid, nyelvfüggetlen értékek (a márkanév `ventMail`)
     | kimaradnak — azoknak épp az a helyes viselkedésük, hogy nem változnak.
     */
    foreach (SUPPORTED_LOCALES as $locale) {
        if ($locale === REFERENCE_LOCALE) {
            continue;
        }

        foreach (localeFiles() as $file) {
            $reference = require lang_path(REFERENCE_LOCALE.'/'.$file.'.php');
            $translations = require lang_path($locale.'/'.$file.'.php');

            $identical = 0;
            $compared = 0;

            foreach (flatKeys($reference) as $key) {
                $source = (string) data_get($reference, $key);

                // A RÖVID értékek (márkanév, kód) nyelvfüggetlenek lehetnek.
                if (mb_strlen($source) < 8) {
                    continue;
                }

                $compared++;

                if ($source === (string) data_get($translations, $key)) {
                    $identical++;
                }
            }

            expect($compared)->toBeGreaterThan(0);
            expect($identical / $compared)->toBeLessThan(0.5);
        }
    }
});

it('a TÁMOGATOTT nyelvek listája a configból jön, nem szét van szórva', function () {
    // Egy hetedik nyelv hozzáadása EGY helyen történjen. Ha a lista a kódban szóródna
    // szét, az új nyelv valahol kimaradna — és ott csendben az angol jelenne meg.
    expect(config('app.supported_locales'))->toBe(SUPPORTED_LOCALES)
        ->and(config('app.fallback_locale'))->toBe(REFERENCE_LOCALE);
});
