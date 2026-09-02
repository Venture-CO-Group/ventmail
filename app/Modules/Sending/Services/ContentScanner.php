<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

/**
 * Tartalom-vizsgálat a kimenő levélen — a webmail SZEMÉVEL.
 *
 * A korábbi séma-regex azt feltételezte, hogy a séma egybefüggő ASCII-blokk, közvetlenül
 * kettősponttal. A böngésző és a webmail viszont ELŐBB dekódolja a HTML-entitásokat, és
 * eltávolítja a URL-ből a beágyazott TAB/CR/LF-et meg a vezető kontrollbájtokat — csak
 * UTÁNA nézi meg a sémát. Ezért a `&#106;avascript:`, a `java&Tab;script:` és a
 * `java\tscript:` mind átment a kapun, miközben a `javascript:` fennakadt: pontosan a
 * valódi vektorok mentek át.
 *
 * Itt a bemenetet UGYANÚGY normalizáljuk, ahogy a fogadó kliens teszi, és csak utána
 * illesztünk. A séma-allowlist mellé bekerült az a néhány szerkezet, amit e-mailben
 * egyetlen legitim küldő sem használ (eseménykezelő, `srcdoc`, `meta refresh`, script/
 * iframe/object/embed) — a webmailek ezeket amúgy is kiszedik, tehát a tiltásuk nem vesz
 * el működő funkciót, viszont megszünteti a felületet.
 */
final class ContentScanner
{
    public function __construct(private readonly MimeTextExtractor $extractor = new MimeTextExtractor) {}

    /** Csak ezek a sémák jelenhetnek meg linkként. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** URL-t hordozó attribútumok — mindegyik séma-ellenőrzés alá esik. */
    private const URL_ATTRIBUTES = 'href|src|srcset|action|formaction|poster|background|cite|data|longdesc';

    /**
     * Sémák, amiket SZÖVEGES törzsben is elutasítunk.
     *
     * A text/plain részt egyes kliensek linkesítik: ott a séma-allowlist túl szigorú lenne
     * (a sima szöveg tele van „Megjegyzés:" alakú kettőspontokkal), de a végrehajtható
     * sémák tiltása nem.
     */
    private const EXECUTABLE_SCHEMES = ['javascript', 'vbscript', 'data', 'file', 'jar'];

    /** @return string|null a megsértett szabály rövid neve, vagy `null`, ha tiszta */
    public function firstViolation(string $html): ?string
    {
        $normalized = $this->normalize($html);

        if (preg_match('/<\s*(script|iframe|object|embed|base|frame|frameset)\b/i', $normalized, $match) === 1) {
            return 'tiltott elem: <'.strtolower($match[1]).'>';
        }

        // Eseménykezelő: a webmailek kiszedik, tehát legitim küldőnek sem hiányzik — a
        // jelenléte viszont azt jelzi, hogy a törzs egy nem e-mailre szánt oldalról jön.
        if (preg_match('/\son[a-z]{3,20}\s*=/i', $normalized) === 1) {
            return 'eseménykezelő attribútum (on*)';
        }

        if (preg_match('/\bsrcdoc\s*=/i', $normalized) === 1) {
            return 'srcdoc attribútum';
        }

        if (preg_match('/http-equiv\s*=\s*["\']?\s*refresh/i', $normalized) === 1) {
            return 'meta refresh';
        }

        if (preg_match('/expression\s*\(/i', $normalized) === 1) {
            return 'CSS expression()';
        }

        // URL-attribútumok sémái.
        preg_match_all(
            '/\b(?:'.self::URL_ATTRIBUTES.')\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|`([^`]*)`|([^\s>]+))/i',
            $normalized,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            // A backtickes alak régi IE-vektor, de egy karakterbe kerül lefedni.
            $value = collect([$match[1] ?? '', $match[2] ?? '', $match[3] ?? '', $match[4] ?? ''])
                ->first(static fn (string $candidate) => $candidate !== '') ?? '';

            $scheme = $this->schemeOf($value);

            if ($scheme !== null && ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
                return 'tiltott link-séma ('.$scheme.')';
            }
        }

        // CSS `url(...)` — `style` attribútumban és `<style>` blokkban egyaránt.
        preg_match_all('/url\s*\(\s*["\']?([^"\')]+)/i', $normalized, $urls);

        foreach ($urls[1] ?? [] as $url) {
            $scheme = $this->schemeOf($url);

            if ($scheme !== null && ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
                return 'tiltott CSS-url séma ('.$scheme.')';
            }
        }

        return null;
    }

    /**
     * Szöveges (nem HTML) törzs vizsgálata.
     *
     * Itt nincs attribútum-kontextus, ezért csak a végrehajtható sémákat keressük —
     * különben minden „Feladó:" alakú sor sémának látszana.
     */
    public function firstViolationInText(string $text): ?string
    {
        $normalized = $this->normalize($text);

        foreach (self::EXECUTABLE_SCHEMES as $scheme) {
            if (preg_match('/\b'.preg_quote($scheme, '/').'\s*:/i', $normalized) === 1) {
                return 'tiltott link-séma ('.$scheme.')';
            }
        }

        return null;
    }

    /**
     * Behelyettesítendő VÁLTOZÓ-ÉRTÉK vizsgálata.
     *
     * A hívótól jövő érték a valódi támadási csatorna (`v:*`, `recipient-variables`, később
     * a B1 kontakt-attribútumai). Az értéket külön nézzük, nem a kirenderelt törzs
     * címzettenkénti másolatát: ugyanazt fogja meg, de nem rendereli újra a teljes levelet
     * ezerszer — az a rate-limit ELŐTT álló CPU-erősítő lenne.
     */
    public function firstViolationInValue(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $violation = $this->firstViolationInValue($item);

                if ($violation !== null) {
                    return $violation;
                }
            }

            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = (string) $value;

        // Az érték escape-elve kerül a törzsbe (idézőjel, `<`, `>` semlegesítve), ezért
        // attribútumból kitörni nem tud — a SÉMA viszont escape után is séma marad.
        //
        // Az ÉRTÉKNÉL a whitespace-mentes változatot is nézzük: a `java\tscript:` a
        // böngészőben `javascript:`. Ezt a szöveges TÖRZSRE nem tesszük meg (ott a szavak
        // összeragasztása téves találatot adna), az érték viszont egyetlen URL/mező.
        $collapsed = (string) preg_replace('/[\x00-\x20\x7f]|\xc2\xa0/', '', $this->normalize($text));

        // CSAK a sémákat nézzük: a `<script>` egy változó-értékben ártalmatlan, mert a
        // renderelés escape-eli (`&lt;script&gt;`), és egy legitim hírlevél-változó
        // (terméknév, idézet) tartalmazhat `<` jelet. A séma viszont escape után is séma.
        return $this->firstViolationInText($text) ?? $this->firstViolationInText($collapsed);
    }

    /**
     * NYERS MIME vizsgálata.
     *
     * A MIME-út eddig teljesen kimaradt a tartalom-kapuból (a `body_html` ott üres), tehát
     * amit a strukturált végponton tiltunk, az egy `curl -F message=@level.eml`-lel trükk
     * nélkül kiment volna.
     *
     * A részekre bontás SZERKEZET szerint megy (`MimeTextExtractor`), nem mintaillesztéssel:
     * az első változat egy „két hosszú base64-sor" regexszel keresett kódolt részt, amit
     * (a) 59-es tördeléssel meg lehetett kerülni, (b) négyzetes visszalépése miatt 500 KB
     * törzsön 76 s CPU-t evett — szinkron, a kérés útján.
     */
    public function firstViolationInMime(string $raw): ?string
    {
        foreach ($this->extractor->extract($raw) as $text) {
            $violation = $this->firstViolation($text);

            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * A fogadó kliens normalizálása: entitás-dekódolás, majd a URL-ekből kiszűrt
     * whitespace és C0-kontrollbájtok.
     */
    private function normalize(string $content): string
    {
        return html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** A séma kinyerése úgy, ahogy a kliens látja: a kontrollbájtok és a szóköz eldobva. */
    private function schemeOf(string $url): ?string
    {
        // A nem törő szóköz (U+00A0) is eltűnik: a kliensek URL-parsere sem tartja meg.
        $cleaned = preg_replace('/[\x00-\x20\x7f]|\xc2\xa0/', '', $url) ?? $url;

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $cleaned, $match) !== 1) {
            return null; // relatív URL vagy horgony — nincs séma, nincs mit engedélyezni
        }

        return strtolower($match[1]);
    }
}
