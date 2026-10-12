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

    /** A legbelső feltételes szakasz (nincs benne újabb nyitó jelölő). */
    private const INNERMOST = '/<!--vm:if:([A-Za-z0-9+\/=]{1,2048})-->((?:(?!<!--vm:if:).)*?)<!--vm:endif-->/s';

    /** A beágyazás felső határa — egy rosszindulatú bemenet ne pörgesse a ciklust. */
    private const MAX_PASSES = 32;

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
        return preg_match('/^[A-Za-z0-9+\/=]{1,2048}$/', $encoded) === 1 ? '<!--vm:if:'.$encoded.'-->' : '';
    }

    /**
     * A feltételek KIÉRTÉKELÉSE a címzett változóival.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function apply(string $content, array $variables): string
    {
        if (! str_contains($content, '<!--vm:if:')) {
            return $content;
        }

        for ($pass = 0; $pass < self::MAX_PASSES && str_contains($content, '<!--vm:if:'); $pass++) {
            $next = (string) preg_replace_callback(
                self::INNERMOST,
                static function (array $matches) use ($variables): string {
                    $condition = json_decode((string) base64_decode($matches[1], true), true);

                    // A HIBÁS jelölő ELREJT, nem mutat: egy sérült feltétel mögötti tartalom
                    // (pl. „csak VIP-eknek: 50% kupon") nem kerülhet mindenkihez.
                    if (! is_array($condition) || self::violation($condition) !== null) {
                        return '';
                    }

                    return self::matches($condition, $variables) ? $matches[2] : '';
                },
                $content
            );

            if ($next === $content) {
                break;
            }

            $content = $next;
        }

        // A PÁRATLAN jelölők maradéka ne kerüljön ki a címzetthez.
        return (string) preg_replace('/<!--vm:if:[^>]{0,4096}?-->|<!--vm:endif-->/', '', $content);
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
        preg_match_all('/<!--vm:if:([A-Za-z0-9+\/=]{1,2048})-->/', $content, $matches);

        $segments = [];

        foreach ($matches[1] as $encoded) {
            $condition = json_decode((string) base64_decode($encoded, true), true);

            if (is_array($condition) && ($condition['kind'] ?? null) === 'segment' && is_string($condition['segment'] ?? null)) {
                $segments[] = strtolower($condition['segment']);
            }
        }

        return array_values(array_unique($segments));
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
