import { createI18n, type I18n } from 'vue-i18n';

/**
 * Vue I18n — LAZY locale bundle-ökkel (C2 · 89. kritérium).
 *
 * ## Miért lazy
 *
 * Mert hat nyelv teljes szótára minden látogatónak letöltődne, holott egyszerre egyet
 * használ. A `import.meta.glob` külön chunkot csinál nyelvenként, és csak a kiválasztottat
 * kérjük le.
 *
 * ## A KEZDŐ NYELV a szerverről jön
 *
 * A szerver már eldöntötte (`SetLocale` middleware: a felhasználó `locale`-ja, különben az
 * `Accept-Language`), és a szerver-oldali szövegek (gate-üzenetek, flash) MÁR azon a
 * nyelven érkeznek. Ha a kliens újra döntene, a két réteg elcsúszna, és egy oldalon két
 * nyelv jelenne meg.
 *
 * A hiányzó kulcsot NEM némítjuk el: a `missingWarn` fejlesztésben hangos, mert egy
 * csendben angolul maradó felirat pont az a hiba, amit a kulcs-teljességi teszt is tilt.
 */
const bundles = import.meta.glob<{ default: Record<string, unknown> }>('./locales/*.json');

export const FALLBACK_LOCALE = 'en';

export function createVentmailI18n(locale: string, messages: Record<string, unknown>): I18n {
    return createI18n({
        legacy: false,
        globalInjection: true,
        locale,
        fallbackLocale: FALLBACK_LOCALE,
        messages: { [locale]: messages } as never,
        missingWarn: import.meta.env.DEV,
        fallbackWarn: import.meta.env.DEV,
    });
}

/** Egy nyelv szótárának betöltése — a hívó dönti el, mikor kell. */
export async function loadLocaleMessages(locale: string): Promise<Record<string, unknown>> {
    const loader = bundles[`./locales/${locale}.json`] ?? bundles[`./locales/${FALLBACK_LOCALE}.json`];

    const bundle = await loader();

    return bundle.default;
}

/** A támogatott nyelvek a BUNDLE-ökből — nem egy kézzel karbantartott lista. */
export function availableLocales(): string[] {
    return Object.keys(bundles)
        .map((path) => path.replace('./locales/', '').replace('.json', ''))
        .sort();
}
