<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A SZEGMENS-DSL értelmezése (49. kritérium).
 *
 * ## Miért DSL, és miért nem SQL
 *
 * A szegmens-szerkesztő az ügyfél kezében van. Ha a definíció SQL-töredék lenne — akár csak
 * a `WHERE` része —, a felület egy SQL-injektálási pont lenne a legjobb szándék mellett is:
 * a tenant a saját adatterén kívülre látna, és a `workspace_id` szűrő megkerülhető lenne.
 *
 * Ezért a DSL ZÁRT: a mezőnevek a `FIELDS` térképből jönnek, az operátorok az `OPERATORS`
 * listából, és ami nem illik rájuk, az KIVÉTEL — nem csendes eldobás. A csendes eldobás
 * itt különösen rossz: egy elhagyott szabályból SZÉLESEBB szegmens lesz, tehát több embernek
 * megy ki a levél, mint amit a felhasználó beállított.
 *
 * ## A determinizmus követelménye
 *
 * A szegmensnek UGYANARRA az adatra UGYANAZT a halmazt kell adnia — ez a kampány
 * megismételhetőségének alapja (a B2 célzása és a B4 analitikája ugyanezt a motort hívja).
 * Ezért nincs benne se `random()`, se „legutóbbi N", se implicit rendezés: a rendezés
 * mindig az `id`, a szűrés pedig halmaz-művelet.
 */
final class SegmentEngine
{
    /**
     * A szegmentálható mezők — NÉV → (oszlop, típus).
     *
     * A `attr:` előtag a szabad attribútumokra szól (`attr:city`), és külön ág kezeli: ott a
     * KULCS is a felhasználótól jön, tehát karakterkészletre szorítjuk.
     *
     * @var array<string, array{column: string, type: string}>
     */
    public const FIELDS = [
        'email' => ['column' => 'email', 'type' => 'string'],
        'first_name' => ['column' => 'first_name', 'type' => 'string'],
        'last_name' => ['column' => 'last_name', 'type' => 'string'],
        'status' => ['column' => 'status', 'type' => 'string'],
        'external_id' => ['column' => 'external_id', 'type' => 'string'],
        'validation_verdict' => ['column' => 'validation_verdict', 'type' => 'string'],
        'validation_risk' => ['column' => 'validation_risk', 'type' => 'number'],
        'subscribed_at' => ['column' => 'subscribed_at', 'type' => 'date'],
        'created_at' => ['column' => 'created_at', 'type' => 'date'],
    ];

    /** @var list<string> */
    public const OPERATORS = [
        'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with',
        'gt', 'gte', 'lt', 'lte', 'set', 'not_set',
        'in_list', 'not_in_list', 'has_tag', 'not_has_tag',
        'opened_campaign', 'clicked_campaign', 'not_opened_campaign',
        // v1.1 · relatív dátum és engagement („az elmúlt N napban").
        'within_days', 'not_within_days',
        'opened_within_days', 'clicked_within_days', 'not_opened_within_days', 'not_clicked_within_days', 'never_opened',
    ];

    /** v1.1 · A consent-állapot (marketing csatorna) — zárt értékkészlet; `none` = nincs consent-sor. */
    public const CONSENT_VALUES = ['confirmed', 'pending', 'revoked', 'none'];

    /** v1.1 · A feliratkozási forrás — zárt értékkészlet. */
    public const SOURCE_VALUES = ['import', 'form', 'api', 'manual'];

    /** v1.1 · Az engagement-operátorok (mező: `engagement`). */
    private const ENGAGEMENT_OPERATORS = [
        'opened_within_days', 'clicked_within_days', 'not_opened_within_days', 'not_clicked_within_days', 'never_opened',
    ];

    /**
     * A FELIRATKOZÁSI FORRÁS kifejezése — a dashboard forrás-bontása UGYANEZT használja.
     *
     * A `contacts.source` a v1.1-től töltődik (import / űrlap / API-szinkron / kézi). A régi
     * soroknál a marketing-consent `source`-a a tartalék, és ha az sincs: `manual`. A
     * kifejezés a `contacts` táblára korrelál, a consent-sort a tenant SAJÁT
     * `(workspace_id, email, channel)` egyedi indexén keresi.
     */
    public const SOURCE_EXPRESSION = "coalesce(contacts.source, (select case cs.source when 'form' then 'form' "
        ."when 'import' then 'import' when 'api' then 'api' else null end from consents cs "
        .'where cs.workspace_id = contacts.workspace_id and cs.email = contacts.email '
        ."and cs.channel = 'marketing' limit 1), 'manual')";

    /** A szabályok felső száma — a TELJES fára (csoportokkal együtt). */
    private const MAX_RULES = 50;

    /** A csoportok mélysége (a gyökér az 1.): gyökér + két beágyazott szint. */
    public const MAX_DEPTH = 3;

    /** Az „elmúlt N nap" felső határa (10 év) — a nagyobb érték elírás. */
    private const MAX_DAYS = 3650;

    /** @var array<int, array<string, string>> workspace → (kulcs → típus) */
    private array $fieldTypes = [];

    /**
     * @param  array<string, mixed>  $definition
     * @return Builder<Contact>
     */
    public function query(array $definition): Builder
    {
        // A DETERMINIZMUS alapja: a rendezés MINDIG az `id`, sosem implicit.
        $query = Contact::query()->reachable()->orderBy('id');

        $count = 0;

        $this->applyGroup($query, $definition, 1, $count);

        return $query;
    }

    /** @param array<string, mixed> $definition */
    public function count(array $definition): int
    {
        return $this->query($definition)->count();
    }

    /**
     * EGY CSOPORT (v1.1): `{"match": "all|any", "rules": [szabály | {"group": {…}}]}`.
     *
     * A régi, lapos definíció a gyökér-csoport — visszafelé kompatibilis. A beágyazott
     * csoport NEM lehet üres: egy üres `any`-ág a Laravel-ben nyom nélkül eltűnne, és a
     * szegmens SZÉLESEBB lenne a beállítottnál.
     *
     * @param  array<string, mixed>  $group
     */
    private function applyGroup(Builder $query, array $group, int $depth, int &$count): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf('A csoportok legfeljebb %d szint mélyek lehetnek.', self::MAX_DEPTH));
        }

        $match = strtolower((string) ($group['match'] ?? 'all'));

        if (! in_array($match, ['all', 'any'], true)) {
            throw new InvalidArgumentException("Ismeretlen illesztési mód: {$match} (all|any).");
        }

        $rules = $group['rules'] ?? [];

        if (! is_array($rules) || ! array_is_list($rules)) {
            throw new InvalidArgumentException('A `rules` listát vár.');
        }

        // Az ÜRES szabálylista a gyökéren a TELJES elérhető közönség — de ezt ki kell mondani,
        // mert a másik értelmezés (üres halmaz) épp az ellenkezője. Beágyazva tilos.
        if ($rules === []) {
            if ($depth > 1) {
                throw new InvalidArgumentException('Egy feltétel-csoport nem lehet üres.');
            }

            return;
        }

        $query->where(function (Builder $inner) use ($rules, $match, $depth, &$count): void {
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    throw new InvalidArgumentException('Minden szabály objektum.');
                }

                $method = $match === 'all' ? 'where' : 'orWhere';

                if (array_key_exists('group', $rule)) {
                    if (! is_array($rule['group'])) {
                        throw new InvalidArgumentException('A `group` objektumot vár.');
                    }

                    // TELJES closure `&$count`-tal: az arrow fn ÉRTÉK szerint zárna, és a
                    // csoportok szabályai kimaradnának az összesített plafonból.
                    $inner->{$method}(function (Builder $nested) use ($rule, $depth, &$count): void {
                        $this->applyGroup($nested, $rule['group'], $depth + 1, $count);
                    });

                    continue;
                }

                if (++$count > self::MAX_RULES) {
                    throw new InvalidArgumentException(sprintf('Legfeljebb %d szabály adható meg.', self::MAX_RULES));
                }

                $inner->{$method}(fn (Builder $nested) => $this->applyRule($nested, $rule));
            }
        });
    }

    /**
     * A SZERKESZTŐ KATALÓGUSA — mezők, típusok, operátorok, a tenant egyéni mezőivel.
     *
     * A felület ebből épít legördülőt: a mezők és az operátorok engedélylistája így EGY helyen
     * él (itt), és a kliens nem küldhet olyat, amit a motor nem ismer — ha mégis, a motor
     * hangosan elutasítja.
     *
     * @return array{fields: list<array<string, mixed>>, operators: array<string, list<string>>, maxRules: int, maxDepth: int}
     */
    public function catalog(): array
    {
        $fields = [
            ['key' => 'email', 'type' => 'string', 'group' => 'contact'],
            ['key' => 'first_name', 'type' => 'string', 'group' => 'contact'],
            ['key' => 'last_name', 'type' => 'string', 'group' => 'contact'],
            ['key' => 'status', 'type' => 'enum', 'group' => 'contact', 'values' => ['subscribed', 'pending']],
            ['key' => 'external_id', 'type' => 'string', 'group' => 'contact'],
            ['key' => 'validation_verdict', 'type' => 'string', 'group' => 'contact'],
            ['key' => 'validation_risk', 'type' => 'number', 'group' => 'contact'],
            ['key' => 'created_at', 'type' => 'date', 'group' => 'signup'],
            ['key' => 'subscribed_at', 'type' => 'date', 'group' => 'signup'],
            ['key' => 'source', 'type' => 'enum', 'group' => 'signup', 'values' => self::SOURCE_VALUES],
            ['key' => 'consent', 'type' => 'enum', 'group' => 'consent', 'values' => self::CONSENT_VALUES],
            ['key' => 'engagement', 'type' => 'engagement', 'group' => 'engagement'],
            ['key' => 'list', 'type' => 'list', 'group' => 'membership'],
            ['key' => 'tag', 'type' => 'tag', 'group' => 'membership'],
        ];

        foreach (ContactField::query()->orderBy('label')->get(['key', 'label', 'type']) as $field) {
            $fields[] = [
                'key' => 'attr:'.$field->key,
                'type' => match ((string) $field->type) {
                    'number' => 'number',
                    'date' => 'date',
                    'boolean' => 'boolean',
                    default => 'string',
                },
                'group' => 'custom',
                'label' => (string) $field->label,
            ];
        }

        return [
            'fields' => $fields,
            'operators' => [
                'string' => ['is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'set', 'not_set'],
                'number' => ['is', 'is_not', 'gt', 'gte', 'lt', 'lte', 'set', 'not_set'],
                'date' => ['gt', 'lt', 'within_days', 'not_within_days', 'set', 'not_set'],
                'boolean' => ['is'],
                'enum' => ['is', 'is_not'],
                'engagement' => self::ENGAGEMENT_OPERATORS,
                'list' => ['in_list', 'not_in_list'],
                'tag' => ['has_tag', 'not_has_tag'],
            ],
            'maxRules' => self::MAX_RULES,
            'maxDepth' => self::MAX_DEPTH,
        ];
    }

    /** @param array<string, mixed> $rule */
    private function applyRule(Builder $query, array $rule): void
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = strtolower((string) ($rule['op'] ?? ''));
        $value = $rule['value'] ?? null;

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Ismeretlen operátor: {$operator}.");
        }

        // A LISTA- és CÍMKE-tagság reláció, nem oszlop.
        if (in_array($operator, ['in_list', 'not_in_list', 'has_tag', 'not_has_tag'], true)) {
            // A UUID-t ITT ellenőrizzük, nem az adatbázisban: egy elgépelt azonosító
            // `SQLSTATE[22P02]` QueryExceptiont adott, ami a felületen 500 — pedig ez a
            // felhasználó hibája, és megnevezhető.
            if (! Str::isUuid($this->scalar($value))) {
                throw new InvalidArgumentException(sprintf(
                    'A(z) %s szabály értéke azonosító (uuid) kell legyen.',
                    $operator
                ));
            }

            $this->applyRelation($query, $operator, $value);

            return;
        }

        if (in_array($operator, ['opened_campaign', 'clicked_campaign', 'not_opened_campaign'], true)) {
            $this->applyEvent($query, $operator, $value);

            return;
        }

        if (in_array($operator, self::ENGAGEMENT_OPERATORS, true)) {
            $this->applyEngagement($query, $operator, $value);

            return;
        }

        if ($field === 'consent') {
            $this->applyConsent($query, $operator, $value);

            return;
        }

        if ($field === 'source') {
            $this->applySource($query, $operator, $value);

            return;
        }

        [$column, $type] = $this->resolveField($field);

        // v1.1 · TÍPUSOS egyéni mező (szám / dátum / logikai): saját kifejezés, ami a nem
        // illeszkedő értéket NULL-nak látja — egy „sok" érték a számmezőben nem cast-hiba (500),
        // hanem egyszerűen nem felel meg a feltételnek.
        if (in_array($type, ['json_number', 'json_date', 'json_boolean'], true)) {
            $this->applyTypedAttribute($query, $field, $type, $operator, $value);

            return;
        }

        if (in_array($operator, ['within_days', 'not_within_days'], true)) {
            if ($type !== 'date') {
                throw new InvalidArgumentException('Az „elmúlt N napban" csak dátum-mezőn értelmes.');
            }

            $since = now()->subDays($this->days($value));

            $operator === 'within_days'
                ? $query->where($column, '>=', $since)
                // A HIÁNYZÓ dátum („sosem iratkozott fel") NEM „az elmúlt N napban" — tehát
                // a tagadásba beletartozik.
                : $query->where(fn (Builder $inner) => $inner->whereNull($column)->orWhere($column, '<', $since));

            return;
        }

        // A SZÖVEGES operátorokhoz a NYERS SQL-kifejezés kell, és az JSON-nál MÁS.
        //
        // A `whereRaw` nem megy át az Eloquent JSON-grammaron: az `attributes->city` alak
        // ott maradt, és a Postgres `column "city" does not exist`-tel elhasalt — vagyis a
        // szerkesztő LEGGYAKORIBB operátora a szabad attribútumokon 500-at adott (mérve). A
        // JSON-út a `->>` operátor, ami szöveget ad.
        $expression = $type === 'json'
            ? 'attributes->>\''.$this->jsonKey($field).'\''
            : $column;

        match ($operator) {
            'is' => $query->where($column, '=', $this->typed($value, $type)),
            // A NULL-os sor NEM „nem egyenlő": a háromértékű logika miatt a `!=` kizárná
            // azt is, akinek nincs értéke — pedig az ő keresztneve tényleg nem „Anna".
            'is_not' => $query->whereRaw("coalesce({$expression}::text, '') <> ?", [$this->scalar($value)]),
            // A `contains` MINDIG kis-nagybetű-érzéketlen: a felület felhasználója nem
            // gondol a betűzésre, és a „nem talál semmit" nála hibának látszik.
            'contains' => $query->whereRaw("lower(coalesce({$expression}::text, '')) like ?", ['%'.$this->like($value).'%']),
            // Ugyanaz az ok, mint az `is_not`-nál: a „keresztneve nem tartalmazza X-et"
            // szegmensből különben MINDENKI kimaradt, akinek nincs keresztneve.
            'not_contains' => $query->whereRaw("lower(coalesce({$expression}::text, '')) not like ?", ['%'.$this->like($value).'%']),
            'starts_with' => $query->whereRaw("lower(coalesce({$expression}::text, '')) like ?", [$this->like($value).'%']),
            'ends_with' => $query->whereRaw("lower(coalesce({$expression}::text, '')) like ?", ['%'.$this->like($value)]),
            'gt' => $query->where($column, '>', $this->typed($value, $type)),
            'gte' => $query->where($column, '>=', $this->typed($value, $type)),
            'lt' => $query->where($column, '<', $this->typed($value, $type)),
            'lte' => $query->where($column, '<=', $this->typed($value, $type)),
            // A `set` a JSON-attribútumnál a KULCS LÉTEZÉSÉT jelenti, oszlopnál a nem-NULL-t
            // — a kettő NEM ugyanaz: egy `{"city": null}` attribútumnál a kulcs létezik.
            // A JSONB „kulcs létezik" operátora a `?`, ami a PDO HELYŐRZŐJÉVEL ütközik:
            // `whereRaw('attributes ? ?')` → `syntax error at or near "$3"` (mérve). A
            // függvény-alak (`jsonb_exists`) ugyanazt jelenti, és nem ütközik.
            'set' => $type === 'json'
                ? $query->whereRaw('jsonb_exists(attributes, ?)', [$this->jsonKey($field)])
                : $query->whereNotNull($column),
            'not_set' => $type === 'json'
                ? $query->whereRaw('not jsonb_exists(attributes, ?)', [$this->jsonKey($field)])
                : $query->whereNull($column),
            default => throw new InvalidArgumentException("Kezeletlen operátor: {$operator}."),
        };
    }

    /**
     * TÍPUSOS egyéni mező (a `contact_fields` definíciója szerint).
     *
     * A kulcs a `resolveField()`-ben már karakterkészletre szorított (`[A-Za-z0-9_-]`), tehát a
     * kifejezésbe idézőjel vagy zárójel nem kerülhet. Az ÉRTÉK mindig kötésen megy.
     *
     * - szám: csak a szám-alakú érték kap numerikus alakot (különben NULL — nincs cast-hiba);
     * - dátum: az ISO `ÉÉÉÉ-HH-NN` előtag SZÖVEGKÉNT hasonlít (lexikografikusan = időrendben),
     *   így egy „2024-02-31" sem dob dátum-hibát a lekérdezésben;
     * - logikai: az importált `true`/`false` (és a gyakori igen-alakok).
     */
    private function applyTypedAttribute(Builder $query, string $field, string $type, string $operator, mixed $value): void
    {
        $key = $this->jsonKey($field);
        $raw = "(attributes->>'{$key}')";

        if (in_array($operator, ['set', 'not_set'], true)) {
            $operator === 'set'
                ? $query->whereRaw('jsonb_exists(attributes, ?)', [$key])
                : $query->whereRaw('not jsonb_exists(attributes, ?)', [$key]);

            return;
        }

        if ($type === 'json_boolean') {
            if ($operator !== 'is') {
                throw new InvalidArgumentException('A logikai mező csak „egyenlő" operátorral szűrhető.');
            }

            $truthy = in_array(strtolower($this->scalar($value)), ['1', 'true', 'yes', 'igen'], true);

            $truthy
                ? $query->whereRaw("lower(coalesce({$raw}, '')) in ('true', '1', 'yes', 'igen')")
                : $query->whereRaw("lower(coalesce({$raw}, '')) not in ('true', '1', 'yes', 'igen')");

            return;
        }

        if ($type === 'json_number') {
            $expression = "(case when {$raw} ~ '^\\s*-{0,1}[0-9]+(\\.[0-9]+){0,1}\\s*$' then trim({$raw})::numeric end)";
            $sql = match ($operator) {
                'is' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=',
                'is_not' => null,
                default => throw new InvalidArgumentException("A számmezőn nem értelmezett operátor: {$operator}."),
            };

            $number = $this->typed($value, 'number');

            $sql === null
                ? $query->whereRaw("({$expression} is null or {$expression} <> ?)", [$number])
                : $query->whereRaw("{$expression} {$sql} ?", [$number]);

            return;
        }

        // DÁTUM
        $expression = "(case when {$raw} ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}' then substr({$raw}, 1, 10) end)";

        if (in_array($operator, ['within_days', 'not_within_days'], true)) {
            $since = now()->subDays($this->days($value))->toDateString();

            $operator === 'within_days'
                ? $query->whereRaw("{$expression} >= ?", [$since])
                : $query->whereRaw("({$expression} is null or {$expression} < ?)", [$since]);

            return;
        }

        $sql = match ($operator) {
            'is' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=',
            default => throw new InvalidArgumentException("A dátummezőn nem értelmezett operátor: {$operator}."),
        };

        $date = strtotime($this->scalar($value));

        if ($date === false) {
            throw new InvalidArgumentException('Dátumot vár ez a mező.');
        }

        $query->whereRaw("{$expression} {$sql} ?", [date('Y-m-d', $date)]);
    }

    /**
     * ENGAGEMENT — „megnyitotta / kattintott az elmúlt N napban" (v1.1).
     *
     * A forrás a `campaign_engagement` ténytábla (kampány + journey levelek), NEM a nyers
     * eseménynapló: az a napló-retencióval törlődik (golden rule 10), a ténytábla viszont a
     * riport része és túléli. A kapcsolat a kontakt peppered cím-hash-e (`email_hash` =
     * `recipient_hash`, ugyanaz a `KeyedHash` + `listKey()` alak).
     *
     * A HASH WORKSPACE-FÜGGETLEN (a cím hash-e), ezért a `workspace_id` szűrő itt EXPLICIT és
     * kötelező — enélkül egy másik tenant forgalma döntené el, ki kap levelet.
     *
     * A kattintás nyitásnak is számít: a képek letiltásával a nyitás-pixel nem tölt be, a
     * kattintás viszont bizonyítja, hogy a levelet elolvasták.
     */
    private function applyEngagement(Builder $query, string $operator, mixed $value): void
    {
        $workspaceId = (int) app(WorkspaceContext::class)->workspaceId();

        $base = fn ($inner) => $inner->selectRaw('1')
            ->from('campaign_engagement')
            ->where('campaign_engagement.workspace_id', $workspaceId)
            ->whereColumn('campaign_engagement.recipient_hash', 'contacts.email_hash');

        if ($operator === 'never_opened') {
            // KAPOTT levelet, de SOHA nem nyitotta meg (és nem is kattintott).
            $query->whereExists(fn ($inner) => $base($inner)->where(fn ($q) => $q
                ->where('campaign_engagement.accepted', '>', 0)
                ->orWhere('campaign_engagement.delivered', '>', 0)))
                ->whereNotExists(fn ($inner) => $base($inner)->where(fn ($q) => $q
                    ->where('campaign_engagement.opens', '>', 0)
                    ->orWhere('campaign_engagement.clicks', '>', 0)));

            return;
        }

        $since = now()->subDays($this->days($value));

        $clicked = 'coalesce(campaign_engagement.last_clicked_at, campaign_engagement.first_clicked_at)';
        $opened = "greatest(coalesce(campaign_engagement.last_opened_at, campaign_engagement.first_opened_at), {$clicked})";

        $expression = str_contains($operator, 'clicked') ? $clicked : $opened;

        $exists = fn ($inner) => $base($inner)->whereRaw("{$expression} >= ?", [$since]);

        str_starts_with($operator, 'not_')
            ? $query->whereNotExists($exists)
            : $query->whereExists($exists);
    }

    /**
     * CONSENT-ÁLLAPOT (marketing csatorna) — a PreSendGate 3. kapujának bemenete.
     *
     * A `none` a consent-sor HIÁNYA: az ilyen kontaktnak a kapu marketinget nem enged.
     */
    private function applyConsent(Builder $query, string $operator, mixed $value): void
    {
        $status = $this->scalar($value);

        if (! in_array($status, self::CONSENT_VALUES, true) || ! in_array($operator, ['is', 'is_not'], true)) {
            throw new InvalidArgumentException('A consent-feltétel: is|is_not, értéke: '.implode(', ', self::CONSENT_VALUES).'.');
        }

        $exists = function ($inner) use ($status) {
            $inner->selectRaw('1')
                ->from('consents')
                // A korreláció a kontakt SAJÁT workspace-ére megy (a `contacts` global scope-pal
                // szűrt), a `(workspace_id, email, channel)` egyedi indexen.
                ->whereColumn('consents.workspace_id', 'contacts.workspace_id')
                ->whereColumn('consents.email', 'contacts.email')
                ->where('consents.channel', 'marketing');

            if ($status !== 'none') {
                $inner->where('consents.status', $status);
            }

            return $inner;
        };

        $positive = ($operator === 'is') !== ($status === 'none');

        $positive ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }

    /** FELIRATKOZÁSI FORRÁS — ugyanaz a kifejezés, mint a dashboard forrás-bontásáé. */
    private function applySource(Builder $query, string $operator, mixed $value): void
    {
        $source = $this->scalar($value);

        if (! in_array($source, self::SOURCE_VALUES, true) || ! in_array($operator, ['is', 'is_not'], true)) {
            throw new InvalidArgumentException('A forrás-feltétel: is|is_not, értéke: '.implode(', ', self::SOURCE_VALUES).'.');
        }

        $query->whereRaw(self::SOURCE_EXPRESSION.($operator === 'is' ? ' = ?' : ' <> ?'), [$source]);
    }

    /** Az „elmúlt N nap" értéke: egész, 1..3650. */
    private function days(mixed $value): int
    {
        $raw = $this->scalar($value);

        if (preg_match('/^\d{1,4}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('A napok száma 1 és %d közötti egész.', self::MAX_DAYS));
        }

        return (int) $raw;
    }

    /**
     * A mezőnév feloldása — ZÁRT listából, vagy szabad attribútumként.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveField(string $field): array
    {
        if (isset(self::FIELDS[$field])) {
            return [self::FIELDS[$field]['column'], self::FIELDS[$field]['type']];
        }

        if (str_starts_with($field, 'attr:')) {
            $key = substr($field, 5);

            // A KULCS a felhasználótól jön, és JSON-útvonalként kerül a lekérdezésbe.
            // Engedélyezett karakterkészletre szorítjuk: idézőjel vagy zárójel különben a
            // kifejezésbe kerülhetne. Ugyanaz a minta, mint az Events API `v:*` szűrőjénél.
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) !== 1) {
                throw new InvalidArgumentException("Érvénytelen attribútum-név: {$key}.");
            }

            return ["attributes->{$key}", $this->attributeType($key)];
        }

        throw new InvalidArgumentException(sprintf(
            'Ismeretlen szegmens-mező: %s. Engedélyezett: %s, vagy `attr:<név>`.',
            $field,
            implode(', ', array_keys(self::FIELDS))
        ));
    }

    private function applyRelation(Builder $query, string $operator, mixed $value): void
    {
        $relation = str_contains($operator, 'list') ? 'lists' : 'tags';
        $negated = str_starts_with($operator, 'not_');
        $uuid = $this->scalar($value);

        $constraint = fn (Builder $inner) => $relation === 'lists'
            // A LISTA-tagság csak akkor tagság, ha `subscribed`: egy leiratkozott sor
            // ottmarad (a tény nem törölhető), de a szegmensbe nem tartozik bele.
            ? $inner->where('contact_lists.uuid', $uuid)->where('contact_list_memberships.status', 'subscribed')
            : $inner->where('contact_tags.uuid', $uuid);

        $negated
            ? $query->whereDoesntHave($relation, $constraint)
            : $query->whereHas($relation, $constraint);
    }

    /**
     * ESEMÉNY-alapú szabály (nyitás, klikk) — a Timescale eseménytáblából.
     *
     * A `message_events` a küldés naplója, nem a kontaktoké: a kapcsolat a CÍM. Ezért a
     * lekérdezés a normalizált címre megy, és a `workspace_id`-t a global scope adja.
     */
    private function applyEvent(Builder $query, string $operator, mixed $value): void
    {
        $type = str_contains($operator, 'clicked') ? 'clicked' : 'opened';
        $negated = str_starts_with($operator, 'not_');
        $tag = $this->scalar($value);

        // A `message_events` HYPERTABLE, és nincs rá Eloquent-modell a szegmens-motorban:
        // a korrelált allekérdezés a query builderrel megy. A `workspace_id` EXPLICIT — itt
        // nincs global scope, ami megvédjen, és egy tenant szegmense soha nem láthat bele a
        // másik forgalmába (golden rule 6).
        $workspaceId = (int) app(WorkspaceContext::class)->workspaceId();

        $exists = function ($inner) use ($type, $tag, $workspaceId) {
            $inner->selectRaw('1')
                ->from('message_events')
                // KIS/NAGYBETŰ-FÜGGETLEN egyezés, és ez nem részletkérdés.
                //
                // A `contacts.email` a `listKey()` alakja (a helyi rész is kisbetűs), a
                // `message_events.recipient` viszont a `DomainNormalizer::email()` alakja —
                // ott a helyi rész SZÁNDÉKOSAN érintetlen (RFC 5321 §2.4). Szigorú
                // oszlop-egyezéssel a `Nagy.Anna@…` cím megnyitása nem látszott, tehát a
                // „nem nyitotta meg" újraaktiváló kampány pont AZOKNAK ment ki, akik
                // megnyitották (mérve).
                //
                // A REDAKTÁLT (`NULL`) recipient nem egyezik semmivel — ez helyes: a törölt
                // érintett aktivitása nem hozza vissza őt egy szegmensbe.
                ->whereRaw('lower(message_events.recipient) = contacts.email')
                ->where('message_events.workspace_id', $workspaceId)
                ->where('message_events.type', $type);

            if ($tag !== '') {
                $inner->whereJsonContains('message_events.tags', $tag);
            }

            return $inner;
        };

        $negated
            ? $query->whereNotExists($exists)
            : $query->whereExists($exists);
    }

    /**
     * Az egyéni mező TÍPUSA a `contact_fields` definícióból (tenantonként egyszer olvasva).
     * Definíció nélkül (szabad attribútum) szövegként viselkedik — a v1.1 előtti szemantika.
     */
    private function attributeType(string $key): string
    {
        $workspaceId = (int) app(WorkspaceContext::class)->workspaceId();

        $this->fieldTypes[$workspaceId] ??= ContactField::query()->pluck('type', 'key')
            ->map(fn ($type) => (string) $type)->all();

        return match ($this->fieldTypes[$workspaceId][$key] ?? 'text') {
            'number' => 'json_number',
            'date' => 'json_date',
            'boolean' => 'json_boolean',
            default => 'json',
        };
    }

    /**
     * Az `attr:<név>` kulcs — MÁR ellenőrzött karakterkészlettel.
     *
     * A `resolveField()` regexre szorítja (`[A-Za-z0-9_-]{1,64}`), tehát idézőjel vagy
     * zárójel nem kerülhet a kifejezésbe. A kulcs mégis kötésen megy, ahol a Postgres
     * engedi — a nyers interpoláció csak ott marad, ahol az operátor bal oldala nem
     * paraméterezhető.
     */
    private function jsonKey(string $field): string
    {
        return substr($field, 5);
    }

    /**
     * A LIKE-JOKEREK ESCAPE-ELÉSE.
     *
     * Escape nélkül a `contains` operátor `%` értékkel MINDENKIT beválasztott (mérve), és a
     * `_` egyetlen karakterre illeszkedett. A hiba iránya a rossz: a szegmens SZÉLESEBB lesz
     * a beállítottnál, tehát több embernek megy ki a levél, mint amit a felhasználó akart —
     * pontosan az, amit az osztály docblockja a legrosszabbnak nevez.
     */
    private function like(mixed $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], strtolower($this->scalar($value)));
    }

    private function scalar(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('A szabály értéke egyetlen skalár lehet, nem lista.');
        }

        return (string) $value;
    }

    private function typed(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number' => is_numeric($this->scalar($value))
                ? (float) $this->scalar($value)
                : throw new InvalidArgumentException('Számot vár ez a mező.'),
            // A DÁTUM is ITT bukjon el, ne a Postgresben (`SQLSTATE[22007]` → 500).
            'date' => strtotime($this->scalar($value)) !== false
                ? $this->scalar($value)
                : throw new InvalidArgumentException('Dátumot vár ez a mező.'),
            default => $this->scalar($value),
        };
    }
}
