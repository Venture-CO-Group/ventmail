/**
 * A @venture/ui `VtFileUpload` (0.3.0) feliratai a felület nyelvén — a komponens minden
 * szövege prop (a fogyasztó fordít). Nem komponens-pótlás: csak a 7 nyelvű feliratkészlet
 * egy helyen, hogy az import, a branding és a szerkesztő képfeltöltője ugyanazt mondja (D317).
 *
 * @param t       a hívó `useI18n()` fordítója (setup-ban kérve; itt nincs komponens-kontextus)
 * @param types   a megengedett típusok emberi alakja a hibaüzenetbe (pl. "CSV, TXT")
 * @param maxBytes a méretkorlát bájtban (a szerver konfigurációjából)
 */
export function uploadLabels(
    t: (key: string, values?: Record<string, unknown>) => string,
    types: string,
    maxBytes?: number,
): Record<string, string | number | undefined> {
    const mb = maxBytes === undefined ? undefined : Math.round((maxBytes / 1048576) * 10) / 10;

    return {
        buttonLabel: t('upload.choose'),
        emptyLabel: t('upload.none'),
        dropLabel: t('upload.drop'),
        clearLabel: t('upload.clear'),
        typeErrorLabel: t('upload.typeError', { types }),
        sizeErrorLabel: t('upload.sizeError', { mb: mb ?? '' }),
        maxSize: maxBytes,
    };
}
