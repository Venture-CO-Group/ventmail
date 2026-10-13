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
use Illuminate\Support\Carbon;
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

        $this->assertNotPlatformWide($email);

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

        $this->assertNotPlatformWide($email);

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
        $existing = Suppression::query()
            ->whereNotNull('workspace_id')
            ->where('email', $email)
            ->orderBy('id')
            ->first();

        if ($existing === null) {
            return Suppression::create([
                'email' => $email,
                'reason' => $reasonEnum->value,
                'applies_to' => $scopeEnum->value,
                'expires_at' => $reasonEnum->isPermanent() ? null : $expiresAt,
                'source' => $source,
                'detail' => $detail,
            ]);
        }

        $existingReason = SuppressionReason::tryFrom((string) $existing->reason);
        $existingScope = SuppressionScope::tryFrom((string) $existing->applies_to) ?? SuppressionScope::All;

        $existing->update([
            // A súlyosabb OK marad meg: a panaszt nem írhatja felül egy későbbi soft bounce.
            'reason' => $reasonEnum->severity() > ($existingReason?->severity() ?? 0)
                ? $reasonEnum->value
                : $existing->reason,
            // A hatókör csak BŐVÜLHET (marketing → all), soha nem szűkül.
            'applies_to' => $scopeEnum->isStricterThan($existingScope)
                ? $scopeEnum->value
                : $existingScope->value,
            // A lejárat csak SZIGORODHAT: permanens ok törli, egyébként a korábbi marad.
            'expires_at' => $reasonEnum->isPermanent() ? null : $existing->expires_at,
        ]);

        return $existing->fresh();
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
            // A KÜLÖN LISTÁK (v1.1): okra szűrve, a `(workspace_id, reason, id)` index mentén.
            ->when($reasons !== null, fn ($query) => $query->whereIn('reason', $reasons))
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
            ->map(static fn ($total): int => (int) $total)
            ->all();
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

        $email = DomainNormalizer::listKey(trim($email));
        $existing = Suppression::query()->whereNotNull('workspace_id')->where('email', $email)->first();
        $reason = $existing?->reason;

        $removed = $this->removeForWorkspace($email);

        if ($removed && $existing !== null) {
            app(AuditLogger::class)->log('suppression.removed', $existing, context: [
                'reason' => (string) $reason,
                'justification' => mb_substr($justification, 0, 500),
                'via' => $via,
            ]);
        }

        return $removed;
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
        $email = DomainNormalizer::listKey(trim($email));

        $this->assertNotPlatformWide($email);

        $suppression = Suppression::query()
            ->whereNotNull('workspace_id')
            ->where('email', $email)
            ->orderBy('id')
            ->first();

        if ($suppression === null) {
            return false;
        }

        $reason = SuppressionReason::tryFrom((string) $suppression->reason);

        // A panasz és a leiratkozás az ÉRINTETT akarata — a tenant nem törölheti, hogy
        // újra küldhessen. Operátori úton (SuperAdmin, C2) feloldható.
        if ($reason?->isIrrevocableByTenant() === true) {
            throw new AuthorizationException(
                'A panasz- és leiratkozás-alapú tiltás API-ból nem vonható vissza.'
            );
        }

        return (bool) $suppression->delete();
    }

    /**
     * A platform-szintű tiltás létezését NEM áruljuk el részletekkel: az orákulum lenne
     * arra, hogy egy cím globálisan tiltott-e.
     */
    private function assertNotPlatformWide(string $email): void
    {
        $platformWide = Suppression::query()->whereNull('workspace_id')->where('email', $email)->exists();

        if ($platformWide) {
            throw new AuthorizationException('Ez a cím platform-szinten tiltott; a művelet nem elérhető.');
        }
    }

    private function authorize(ApiKey $key, string $scope): void
    {
        if (! $key->hasScope($scope)) {
            throw new AuthorizationException("Az API-kulcshoz hiányzik a(z) {$scope} scope.");
        }
    }
}
