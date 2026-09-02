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

    /** A MIME-vizsgálat felső korlátja: a kapu a kérés útján fut, nem sorban. */
    private const MAX_SCAN_BYTES = 2_097_152;

    /** Hány base64-blokkot bontunk ki legfeljebb. */
    private const MAX_BASE64_BLOCKS = 20;

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
            '/\b(?:'.self::URL_ATTRIBUTES.')\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
            $normalized,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $value = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));

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
        $collapsed = (string) preg_replace('/[\x00-\x20\x7f]/', '', $this->normalize($text));

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
     * nélkül kiment volna. Teljes MIME-parse helyett a gyakorlati kódolásokat bontjuk ki:
     * a nyers szöveget, a quoted-printable változatát és a base64-blokkokat — mind
     * méretkorláttal, mert ez a kapu a kérés útján fut.
     */
    public function firstViolationInMime(string $raw): ?string
    {
        $capped = substr($raw, 0, self::MAX_SCAN_BYTES);

        $candidates = [$capped, quoted_printable_decode($capped)];

        // Összefüggő base64-blokkok (a HTML-rész tipikus kódolása).
        preg_match_all('/(?:[A-Za-z0-9+\/=]{60,}\r?\n){2,}/', $capped, $blocks);

        foreach (array_slice($blocks[0] ?? [], 0, self::MAX_BASE64_BLOCKS) as $block) {
            $decoded = base64_decode((string) preg_replace('/\s+/', '', $block), strict: false);

            if (is_string($decoded) && $decoded !== '') {
                $candidates[] = substr($decoded, 0, self::MAX_SCAN_BYTES);
            }
        }

        foreach ($candidates as $candidate) {
            $violation = $this->firstViolation($candidate);

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
        $cleaned = preg_replace('/[\x00-\x20\x7f]/', '', $url) ?? $url;

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $cleaned, $match) !== 1) {
            return null; // relatív URL vagy horgony — nincs séma, nincs mit engedélyezni
        }

        return strtolower($match[1]);
    }
}
