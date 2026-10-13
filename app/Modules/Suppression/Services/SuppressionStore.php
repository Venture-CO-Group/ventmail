<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Enums\SuppressionScope;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A suppression-lista egyetlen írási és olvasási pontja (spec 6.2, M0-4 #26).
 *
 * A scope-ellenőrzés ITT van, nem a controllerben: az SMTP relaynek (A2) és a
 * bounce-feldolgozónak nem lesz FormRequestje, viszont ugyanezen kell átmenniük.
 *
 * MONOTONITÁS: a hatókör és a lejárat csak szigorodhat. Enélkül egy `all` hatókörű panaszt
 * `marketing`-re szűkítve a tranzakciós csatorna újra kinyílna a panaszkodó címre.
 */
final class SuppressionStore
{
    /**
     * TESZT-HOROG (review 3 N1, D208): a „nincs sor" döntés és a beszúrás KÖZÉ fut. A
     * két-kapcsolatos teszt ezen át szúr be egy párhuzamos sort, hogy az ütközés utáni
     * újraolvasási ág valóban lefusson. Élesben mindig `null`.
     *
     * @internal
     */
    private ?\Closure $beforeInsert = null;

    /** @internal csak teszthez */
    public function setBeforeInsertHook(?\Closure $hook): void
    {
        $this->beforeInsert = $hook;
    }

    public function add(
        ApiKey $key,
        string $email,
        string $reason,
        string $appliesTo = 'all',
        ?Carbon $expiresAt = null,
    ): Suppression {
        $this->authorize($key, 'suppressions:write');

        $reasonEnum = SuppressionReason::tryFrom($reason)
            ?? throw new InvalidArgumentException("Ismeretlen suppression-ok: {$reason}");
        $scopeEnum = SuppressionScope::tryFrom($appliesTo)
            ?? throw new InvalidArgumentException("Ismeretlen suppression-hatókör: {$appliesTo}");

        $email = DomainNormalizer::listKey(trim($email));

        // A platform-szintű tiltás létezését NEM áruljuk el (D187): a felvétel ugyanúgy a
        // tenant SAJÁT sorát írja, mint bármely más címnél. A címet a platform-sor a
        // PreSendGate 2. kapujában ettől függetlenül blokkolja.
        return $this->upsert($email, $reasonEnum, $scopeEnum, $expiresAt, 'api:'.$key->prefix);
    }

    /**
     * UGYANAZ A FELVÉTEL, API-kulcs nélkül — a session-hitelesített felületnek.
     *
     * A `source` itt `ui`: a naplóból meg kell tudni különböztetni a kézzel felvett tiltást
     * a bounce-kezelő automatikus soraitól — egy reputáció-vizsgálatnál ez az első kérdés.
     */
    public function addFromUi(
        string $email,
        string $reason,
        string $appliesTo = 'all',
        ?Carbon $expiresAt = null,
    ): Suppression {
        $reasonEnum = SuppressionReason::tryFrom($reason)
            ?? throw new InvalidArgumentException("Ismeretlen suppression-ok: {$reason}");
        $scopeEnum = SuppressionScope::tryFrom($appliesTo)
            ?? throw new InvalidArgumentException("Ismeretlen suppression-hatókör: {$appliesTo}");

        $email = DomainNormalizer::listKey(trim($email));

        return $this->upsert($email, $reasonEnum, $scopeEnum, $expiresAt, 'ui');
    }

    /**
     * A suppression-sor EGYETLEN írási pontja — monoton szigorítással.
     *
     * KIZÁRÓLAG a saját sorok között keresünk: a platform-sorokat a scope amúgy visszaadná,
     * és egy globális találat vagy 500-at okozna, vagy elnyelné a tenant saját tiltását (ami
     * az operátori feloldás után nyomtalanul eltűnne).
     */
    private function upsert(
        string $email,
        SuppressionReason $reasonEnum,
        SuppressionScope $scopeEnum,
        ?Carbon $expiresAt,
        string $source,
        ?string $detail = null,
    ): Suppression {
        /*
         | SOROS FELDOLGOZÁS (v1.1 · 7. modul review 2 S1, D198).
         |
         | Zár nélkül két párhuzamos felvétel ugyanazt a RÉGI sort olvasná: a második (pl.
         | `blocked`) felülírhatná a közben beírt leiratkozást, ami így törölhetővé válna; nem
         | létező sornál pedig mindkettő INSERT-et adna, és a második unique-hibával 500-zal
         | bukna (a one-click POST nem rögzülne). Ezért: a meglévő sor `FOR UPDATE`, a
         | beszúrás savepointban, ütközésnél a nyertes sor újraolvasva `FOR UPDATE` — és a
         | `merged()` szabály mindig a ZÁROLT, friss állapotra fut.
         */
        return DB::transaction(function () use ($email, $reasonEnum, $scopeEnum, $expiresAt, $source, $detail): Suppression {
            $existing = $this->lockedOwnRow($email);

            if ($existing === null) {
                if ($this->beforeInsert !== null) {
                    ($this->beforeInsert)($email);
                }

                $created = $this->createUnlessExists([
                    'email' => $email,
                    'reason' => $reasonEnum->value,
                    'applies_to' => $scopeEnum->value,
                    'expires_at' => $reasonEnum->isPermanent() ? null : $expiresAt,
                    'source' => $source,
                    'detail' => $detail,
                ]);

                if ($created !== null) {
                    return $created;
                }

                // Egy párhuzamos kérés közben beszúrta: az ő sorára fésülünk.
                $existing = $this->lockedOwnRow($email) ?? throw new \RuntimeException('A tiltás-sor eltűnt az ütközés után.');
            }

            $existingReason = SuppressionReason::fromStored((string) $existing->reason);
            $existingScope = SuppressionScope::tryFrom((string) $existing->applies_to) ?? SuppressionScope::All;
            $merged = SuppressionReason::merged($existingReason, $reasonEnum);

            $existing->update([
                // A visszavonhatatlan ok RAGADÓS, egyébként a súlyosabb marad (D186).
                'reason' => $merged->value,
                // A hatókör csak BŐVÜLHET (marketing → all), soha nem szűkül.
                'applies_to' => $scopeEnum->isStricterThan($existingScope)
                    ? $scopeEnum->value
                    : $existingScope->value,
                // A lejárat csak SZIGORODHAT: permanens ok törli, egyébként a korábbi marad.
                'expires_at' => $reasonEnum->isPermanent() || $merged->isPermanent() ? null : $existing->expires_at,
            ]);

            return $existing->fresh();
        });
    }

    /** A workspace SAJÁT sora a címre, `FOR UPDATE` zárral (a hívó tranzakciójában). */
    private function lockedOwnRow(string $email): ?Suppression
    {
        return Suppression::query()
            ->whereNotNull('workspace_id')
            ->where('email', $email)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Beszúrás SAVEPOINTBAN: a `(workspace_id, email)` egyedi kulcs ütközése nem mérgezi a
     * hívó tranzakcióját, és nem 500 — `null`-t ad, a hívó a nyertes sort zárolja újra.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUnlessExists(array $attributes): ?Suppression
    {
        try {
            return DB::transaction(fn () => Suppression::create($attributes));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * ONE-CLICK LEIRATKOZÁS (RFC 8058) — a hitelesítés maga a TOKEN, nem API-kulcs.
     *
     * Külön metódus, és nem a controllerben elhelyezett `firstOrCreate`, mert a naiv
     * változat két úton is 500-zal szállt el, és mindkettő a leiratkozást tette
     * lehetetlenné:
     *
     *  - a keresés a NYERS címmel ment, az írás normalizálva (`setEmailAttribute`), tehát a
     *    `Anna@pelda.hu`-ra érkező MÁSODIK one-click POST (a Gmail újrapróbál, és a
     *    felhasználó a POST után a linkre is rákattint) unique-violationbe futott;
     *  - a tábla egyedi kulcsa `(workspace_id, email)`, `applies_to` NÉLKÜL: ha a címnek már
     *    volt `all` hatókörű sora (bounce, panasz, kézi tiltás — ez az alapérték), már az
     *    ELSŐ POST elszállt.
     *
     * A hatókör MONOTON: aki már `all` hatókörrel tiltott, azon a leiratkozás nem lazít.
     * A platform-szintű tiltásnál nincs mit tenni — és nem is áruljuk el, hogy létezik.
     */
    public function recordUnsubscribe(string $email, string $source = 'one_click', ?string $detail = null): Suppression
    {
        $email = DomainNormalizer::listKey(trim($email));

        return $this->upsert($email, SuppressionReason::Unsubscribe, SuppressionScope::Marketing, null, $source, $detail);
    }

    /**
     * AUTOMATIKUS BOUNCE-TILTÁS a kézbesítési eredményből (D206).
     *
     * A v1.1 előtt a `RecordDeliveryOutcome` `hard_bounce`/`soft_bounce` okot írt közvetlenül
     * (enumon kívül: egyik listán sem látszott, és a `merged()` sem ismerte). Mostantól a
     * közös írási ponton megy, `bounce` okkal, a hard/soft jelleg a `detail`-ben — így a
     * monotonitás és a ragadós okok (D186) erre az útra is érvényesek.
     */
    public function recordBounce(string $email, bool $hard): Suppression
    {
        $email = DomainNormalizer::listKey(trim($email));

        return $this->upsert($email, SuppressionReason::Bounce, SuppressionScope::All, null, 'bounce', $hard ? 'hard' : 'soft');
    }

    /** @return CursorPaginator<Suppression> */
    public function list(ApiKey $key, int $perPage = 100): CursorPaginator
    {
        $this->authorize($key, 'suppressions:read');

        return $this->listForWorkspace($perPage);
    }

    /**
     * UGYANAZ A LISTA, API-kulcs nélkül — a session-hitelesített FELÜLETNEK.
     *
     * A jogosultságot itt a hívó dönti el (`Gate::authorize('suppression.manage')`), mert a
     * felületen nincs API-kulcs. A SZABÁLYOK viszont közösek: ha a lapozás vagy a rendezés
     * két helyen élne, a felület és az API előbb-utóbb mást mutatna ugyanarra a kérdésre.
     *
     * @return CursorPaginator<Suppression>
     */
    public function listForWorkspace(int $perPage = 100, ?string $cursor = null, ?string $search = null, ?array $reasons = null): CursorPaginator
    {
        // Lapozás KÖTELEZŐ: a tábla természeténél fogva milliós, a teljes betöltés
        // memóriára futna — és nyílt e-mail-címeket öntene egy válaszba.
        return Suppression::query()
            // KIZÁRÓLAG a saját sorok (D187): a platform-szintű tiltás (spamcsapda, jogi
            // tiltás) nem tenant-adat, és a listázás/keresés orákulum lenne a létezésére.
            ->whereNotNull('workspace_id')
            // A KÜLÖN LISTÁK (v1.1): okra szűrve, a `(workspace_id, reason, id)` index mentén.
            ->when($reasons !== null, fn ($query) => $query->whereIn('reason', SuppressionReason::storedValues($reasons)))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                // A `like` metakaraktereit escape-eljük: a `%` különben MINDENT adna, és a
                // felhasználó azt hinné, a szűrője nem működik.
                $query->where('email', 'like', '%'.addcslashes(mb_strtolower(trim($search)), '%_\\').'%');
            })
            ->orderByDesc('id')
            ->cursorPaginate(min($perPage, 1000), ['*'], 'cursor', $cursor);
    }

    /**
     * Darabszám okonként — EGY csoportosító lekérdezés a saját sorokra (a fülek számaihoz).
     *
     * @return array<string, int>
     */
    public function countsByReason(): array
    {
        return Suppression::query()
            ->whereNotNull('workspace_id')
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason')
            // A legacy `hard_bounce`/`soft_bounce` a `bounce`-hoz számít (D206).
            ->reduce(static function (array $carry, $total, $reason): array {
                $key = SuppressionReason::fromStored((string) $reason)?->value ?? (string) $reason;
                $carry[$key] = ($carry[$key] ?? 0) + (int) $total;

                return $carry;
            }, []);
    }

    /**
     * TÖRLÉS INDOKLÁSSAL (v1.1 · 7. modul) — a felület és a `/v1` közös útja.
     *
     * Az indok kötelező, és a tény auditált (`suppression.removed`: ok, forrás, indoklás);
     * a panasz és a leiratkozás továbbra sem törölhető (`AuthorizationException`).
     *
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function removeWithJustification(string $email, string $justification, string $via): bool
    {
        $justification = trim($justification);

        if (mb_strlen($justification) < 3) {
            throw new InvalidArgumentException('A törléshez indoklás kötelező.');
        }

        // EGY tranzakció, a ZÁROLT soron dönt és auditál (review 3 S1, D207): a döntés és a
        // törlés között egy párhuzamos one-click leiratkozás nem csúszhat be.
        return DB::transaction(function () use ($email, $justification, $via): bool {
            $deleted = $this->deleteLockedOwnRow($email);

            if ($deleted === null) {
                return false;
            }

            app(AuditLogger::class)->log('suppression.removed', $deleted, context: [
                'reason' => SuppressionReason::fromStored((string) $deleted->reason)?->value ?? (string) $deleted->reason,
                'justification' => mb_substr($justification, 0, 500),
                'via' => $via,
            ]);

            return true;
        });
    }

    public function remove(ApiKey $key, string $email): bool
    {
        $this->authorize($key, 'suppressions:write');

        return $this->removeForWorkspace($email);
    }

    /**
     * UGYANAZ A TÖRLÉS, API-kulcs nélkül — a felületnek.
     *
     * A megszorítások itt élnek, egy helyen: platform-szintű tiltást a tenant nem old fel,
     * és a panasz/leiratkozás alapú tiltás sem vonható vissza (az az ÉRINTETT akarata). Ha
     * a felület a saját törlési útját írná meg, ezek a szabályok pont ott hiányoznának,
     * ahol a legkönnyebb kattintani.
     */
    public function removeForWorkspace(string $email): bool
    {
        return DB::transaction(fn (): bool => $this->deleteLockedOwnRow($email) !== null);
    }

    /**
     * A saját sor törlése `FOR UPDATE` zár ALATT (D207) — a hívó tranzakciójában.
     *
     * Zár nélkül a törlés egy elavult olvasásra döntött: közben egy one-click leiratkozás
     * `unsubscribe`-ra írhatta a sort, a `DELETE … WHERE id = ?` pedig READ COMMITTED alatt
     * a friss sort törölte volna. Most a visszavonhatatlanság a ZÁROLT állapoton dől el.
     *
     * Platform-szintű tiltásra ugyanaz a válasz, mint egy nem létező címre (D187).
     *
     * @return Suppression|null a törölt sor, vagy `null`, ha nem volt mit törölni
     *
     * @throws AuthorizationException panasz/leiratkozás esetén
     */
    private function deleteLockedOwnRow(string $email): ?Suppression
    {
        $suppression = $this->lockedOwnRow(DomainNormalizer::listKey(trim($email)));

        if ($suppression === null) {
            return null;
        }

        // A panasz és a leiratkozás az ÉRINTETT akarata — a tenant nem törölheti, hogy
        // újra küldhessen. Operátori úton (SuperAdmin, C2) feloldható.
        if (SuppressionReason::fromStored((string) $suppression->reason)?->isIrrevocableByTenant() === true) {
            throw new AuthorizationException(
                'A panasz- és leiratkozás-alapú tiltás API-ból nem vonható vissza.'
            );
        }

        $suppression->delete();

        return $suppression;
    }

    private function authorize(ApiKey $key, string $scope): void
    {
        if (! $key->hasScope($scope)) {
            throw new AuthorizationException("Az API-kulcshoz hiányzik a(z) {$scope} scope.");
        }
    }
}
