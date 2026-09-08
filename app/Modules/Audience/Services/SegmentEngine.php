<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Models\Contact;
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
    ];

    /** A szabályok felső száma: egy 200 ágú szegmens lekérdezése a felületet állítaná meg. */
    private const MAX_RULES = 50;

    /**
     * @param  array<string, mixed>  $definition
     * @return Builder<Contact>
     */
    public function query(array $definition): Builder
    {
        $match = strtolower((string) ($definition['match'] ?? 'all'));

        if (! in_array($match, ['all', 'any'], true)) {
            throw new InvalidArgumentException("Ismeretlen illesztési mód: {$match} (all|any).");
        }

        $rules = $definition['rules'] ?? [];

        if (! is_array($rules)) {
            throw new InvalidArgumentException('A `rules` listát vár.');
        }

        if (count($rules) > self::MAX_RULES) {
            throw new InvalidArgumentException(sprintf(
                'Legfeljebb %d szabály adható meg (%d érkezett).',
                self::MAX_RULES,
                count($rules)
            ));
        }

        // A DETERMINIZMUS alapja: a rendezés MINDIG az `id`, sosem implicit.
        $query = Contact::query()->reachable()->orderBy('id');

        // Az ÜRES szabálylista a TELJES elérhető közönség — de ezt ki kell mondani, mert a
        // másik értelmezés (üres halmaz) épp az ellenkezője, és egy elrontott szerkesztő
        // csendben nulla címzettet adna egy kampánynak.
        if ($rules === []) {
            return $query;
        }

        $query->where(function (Builder $group) use ($rules, $match): void {
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    throw new InvalidArgumentException('Minden szabály objektum.');
                }

                $match === 'all'
                    ? $group->where(fn (Builder $inner) => $this->applyRule($inner, $rule))
                    : $group->orWhere(fn (Builder $inner) => $this->applyRule($inner, $rule));
            }
        });

        return $query;
    }

    /** @param array<string, mixed> $definition */
    public function count(array $definition): int
    {
        return $this->query($definition)->count();
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

        [$column, $type] = $this->resolveField($field);

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

            return ["attributes->{$key}", 'json'];
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
