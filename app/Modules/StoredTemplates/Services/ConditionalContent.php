<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Services;

/**
 * FELTÉTELES TARTALOM a renderelt levélben (v1.1 · feltételes blokk).
 *
 * ## A szintaxis a RENDERERÉ, nem a szerkesztőé
 *
 * A szerkesztő blokk-szinten mondja ki: „ez a blokk csak a Törzsvendégek szegmensnek
 * látszik". A döntés viszont CÍMZETTENKÉNT, a küldés pillanatában születik — ugyanott, ahol
 * a merge-tag behelyettesítés (`TemplateRenderer`). Ezért a jelölés is itt él, egy helyen:
 * a fordító ezt a jelölőt írja, a renderer ezt értékeli ki. Két külön minta (egy a
 * fordítóban, egy a rendererben) előbb-utóbb elcsúszna — ezt a hibaosztályt a `MergeTag`
 * docblockja már egyszer leírta.
 *
 * A jelölő HTML-komment, tehát egy renderer nélküli nézetben (nyers forrás) sem töri el a
 * levelet:
 *
 *     <!--vm:if:BASE64(JSON)-->…tartalom…<!--vm:endif-->
 *
 * A feltétel BASE64-ben utazik: a felhasználó által írt érték (`city = "--><script>"`) így
 * nem tud kitörni a kommentből, és nem kell külön escape-szabály.
 *
 * ## A feltétel-típusok
 *
 * - `field`: `contact.first_name`, `contact.attributes.city` … — `equals`, `not_equals`,
 *   `contains`, `is_set`, `is_not_set`. Kis-nagybetűre érzéketlen, szélek levágva.
 * - `segment`: a címzett benne van-e (`in`) vagy nincs (`not_in`) egy szegmensben. A tagság
 *   a `contact.segments` változóból jön (a kampány-küldés számolja ki).
 *
 * ## A HIÁNYZÓ adat
 *
 * Ha a változó nincs meg (API-hívás kontakt-adat nélkül), a `field` feltétel üres értéket
 * lát, a `segment` pedig üres tagságot. A blokk tehát a `is_not_set` / `not_in` ágon jelenik
 * meg — determinisztikusan, nem véletlenszerűen.
 */
final class ConditionalContent
{
    public const END = '<!--vm:endif-->';

    public const FIELD_OPERATORS = ['equals', 'not_equals', 'contains', 'is_set', 'is_not_set'];

    public const SEGMENT_OPERATORS = ['in', 'not_in'];

    /** @param array<string, mixed> $condition */
    public static function open(array $condition): string
    {
        return self::openEncoded(self::encode($condition));
    }

    /**
     * A feltétel kódolt alakja (a fordító attribútumként viszi tovább az MJML-ben).
     *
     * @param  array<string, mixed>  $condition
     */
    public static function encode(array $condition): string
    {
        return base64_encode((string) json_encode(self::normalize($condition), JSON_UNESCAPED_UNICODE));
    }

    /** A nyitó jelölő egy már kódolt feltételből — a nem-base64 bemenet üres jelölőt ad. */
    public static function openEncoded(string $encoded): string
    {
        return $encoded !== '' && strlen($encoded) <= self::MAX_MARKER
            && strspn($encoded, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') === strlen($encoded)
            ? self::OPEN.$encoded.'-->'
            : '';
    }

    /**
     * A feltételben hivatkozott kontakt-mezők (`contact.attributes.x` → `x`) — az
     * adatminimalizáláshoz: a küldő csak ezeket az attribútumokat adja át.
     *
     * @return list<string>
     */
    public static function referencedAttributes(string $content): array
    {
        $keys = [];

        foreach (self::markers($content) as $condition) {
            if (($condition['kind'] ?? null) === 'field' && str_starts_with((string) ($condition['field'] ?? ''), 'contact.attributes.')) {
                $keys[] = substr((string) $condition['field'], strlen('contact.attributes.'));
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<array<string, mixed>> a tartalom érvényes feltételei */
    private static function markers(string $content): array
    {
        $out = [];
        $offset = 0;

        while (($start = strpos($content, self::OPEN, $offset)) !== false) {
            $close = strpos($content, '-->', $start);

            if ($close === false) {
                break;
            }

            $decoded = json_decode((string) base64_decode(substr($content, $start + strlen(self::OPEN), $close - $start - strlen(self::OPEN)), true), true);

            if (is_array($decoded) && self::violation($decoded) === null) {
                $out[] = $decoded;
            }

            $offset = $close + 3;
        }

        return $out;
    }

    private const OPEN = '<!--vm:if:';

    private const MAX_MARKER = 2048;

    /** A beágyazás felső határa — mélyebb fa csak hamisított bemenetből jöhet. */
    private const MAX_DEPTH = 32;

    /**
     * A feltételek KIÉRTÉKELÉSE a címzett változóival.
     *
     * ## Lineáris elemző, nem regex (SB1)
     *
     * A korábbi `(?:(?!…).)*?` minta a PCRE JIT veremkorlátján ~24 KB feltételes tartalom
     * felett `null`-t adott, amit a `(string)` cast ÜRES törzzsé alakított — a levél (a
     * láblécével együtt) üresen ment volna ki. Itt `strpos`-alapú, egyetlen menetes
     * bejárás van veremmel: a futásidő és a memória a bemenettel lineáris, regex nincs.
     *
     * ## Fail-closed
     *
     * Hibás, értelmezhetetlen vagy le nem zárt jelölő mögötti tartalom ELREJTŐDIK; a
     * páratlan záró jelölő eltűnik. A túl mély beágyazás kivételt dob — a hívó (a
     * dispatcher) ilyenkor nem küld, nem egy félig kiértékelt levelet.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function apply(string $content, array $variables): string
    {
        return self::walk($content, static fn (?array $condition): bool => $condition !== null && self::matches($condition, $variables));
    }

    /**
     * MINDEN feltételes szakasz eltávolítása (jelölővel és tartalommal együtt) — a nem
     * Studio-eredetű törzsekre (pl. `/v1` hívó, tárolt sablon): ott a feltétel adata nem a
     * mi kontaktunké, a „csak VIP-eknek" tartalom tehát senkihez nem kerülhet ki.
     */
    public static function strip(string $content): string
    {
        return self::walk($content, static fn (): bool => false);
    }

    /** @param callable(?array<string, mixed>): bool $visible */
    private static function walk(string $content, callable $visible): string
    {
        if (! str_contains($content, '<!--vm:')) {
            return $content;
        }

        $out = '';
        $stack = [];
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $next = strpos($content, '<!--vm:', $offset);

            if ($next === false) {
                if (! in_array(false, $stack, true)) {
                    $out .= substr($content, $offset);
                }

                break;
            }

            if (! in_array(false, $stack, true)) {
                $out .= substr($content, $offset, $next - $offset);
            }

            if (substr_compare($content, self::END, $next, strlen(self::END)) === 0) {
                // Páratlan záró jelölő: eltűnik.
                array_pop($stack);
                $offset = $next + strlen(self::END);

                continue;
            }

            $close = strpos($content, '-->', $next);

            if ($close === false) {
                // Le nem zárt komment: a maradék nem értelmezhető — elrejtjük.
                break;
            }

            $marker = substr($content, $next, $close + 3 - $next);
            $offset = $close + 3;

            if (! str_starts_with($marker, self::OPEN)) {
                // Más `<!--vm:` alak: nem a mi jelölőnk, eltűnik (tartalom nélkül).
                continue;
            }

            if (count($stack) >= self::MAX_DEPTH) {
                throw new \RuntimeException('A feltételes tartalom beágyazása túl mély.');
            }

            $encoded = substr($marker, strlen(self::OPEN), -3);
            $condition = null;

            if ($encoded !== '' && strlen($encoded) <= self::MAX_MARKER && strspn($encoded, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') === strlen($encoded)) {
                $decoded = json_decode((string) base64_decode($encoded, true), true);
                $condition = is_array($decoded) && self::violation($decoded) === null ? $decoded : null;
            }

            $stack[] = (bool) $visible($condition);
        }

        return $out;
    }

    /**
     * Érvényes-e a feltétel? A hiba OKA, vagy `null`.
     */
    public static function violation(mixed $condition): ?string
    {
        if (! is_array($condition)) {
            return 'a feltétel objektum kell legyen';
        }

        $unknown = array_diff(array_keys($condition), ['kind', 'field', 'segment', 'op', 'value', 'label']);

        if ($unknown !== []) {
            return 'ismeretlen mező a feltételben: '.implode(', ', $unknown);
        }

        $kind = $condition['kind'] ?? null;
        $op = $condition['op'] ?? null;

        if ($kind === 'field') {
            if (! is_string($condition['field'] ?? null)
                || preg_match('/^contact\.(?:[a-z0-9_]{1,64}|attributes\.[a-z0-9_]{1,64})$/', $condition['field']) !== 1) {
                return 'a mező `contact.<név>` vagy `contact.attributes.<név>` alakú lehet';
            }

            if (! in_array($op, self::FIELD_OPERATORS, true)) {
                return 'a művelet: '.implode(', ', self::FIELD_OPERATORS);
            }

            if (in_array($op, ['equals', 'not_equals', 'contains'], true)
                && (! is_scalar($condition['value'] ?? null) || mb_strlen((string) $condition['value']) > 200)) {
                return 'az összehasonlítás értéke legfeljebb 200 karakteres szöveg lehet';
            }

            return null;
        }

        if ($kind === 'segment') {
            if (! is_string($condition['segment'] ?? null)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $condition['segment']) !== 1) {
                return 'a szegmens azonosítója UUID kell legyen';
            }

            if (! in_array($op, self::SEGMENT_OPERATORS, true)) {
                return 'a művelet: '.implode(', ', self::SEGMENT_OPERATORS);
            }

            return null;
        }

        return 'a feltétel típusa `field` vagy `segment` lehet';
    }

    /**
     * A feltételben hivatkozott szegmensek — a küldő ezekre számolja ki a tagságot.
     *
     * @return list<string>
     */
    public static function referencedSegments(string $content): array
    {
        $segments = [];

        foreach (self::markers($content) as $condition) {
            if (($condition['kind'] ?? null) === 'segment') {
                $segments[] = strtolower((string) $condition['segment']);
            }
        }

        $segments = array_values(array_unique($segments));
        sort($segments);

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $variables
     */
    public static function matches(array $condition, array $variables): bool
    {
        if (($condition['kind'] ?? null) === 'segment') {
            $segments = array_map(
                static fn ($uuid) => strtolower((string) $uuid),
                (array) data_get($variables, 'contact.segments', [])
            );

            $in = in_array(strtolower((string) $condition['segment']), $segments, true);

            return ($condition['op'] ?? 'in') === 'not_in' ? ! $in : $in;
        }

        $actual = data_get($variables, (string) ($condition['field'] ?? ''));
        $actual = is_scalar($actual) ? mb_strtolower(trim((string) $actual)) : '';
        $expected = mb_strtolower(trim((string) ($condition['value'] ?? '')));

        return match ($condition['op'] ?? null) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'contains' => $expected !== '' && str_contains($actual, $expected),
            'is_set' => $actual !== '',
            'is_not_set' => $actual === '',
            default => false,
        };
    }

    /**
     * A tárolt alak: csak az ismert kulcsok, kanonikus sorrendben (determinisztikus kimenet).
     *
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private static function normalize(array $condition): array
    {
        $out = [];

        foreach (['kind', 'field', 'segment', 'op', 'value'] as $key) {
            if (array_key_exists($key, $condition)) {
                $out[$key] = $condition[$key];
            }
        }

        return $out;
    }
}
