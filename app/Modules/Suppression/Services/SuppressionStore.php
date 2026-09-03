<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Services;

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

        // KIZÁRÓLAG a saját sorok között keresünk: a platform-sorokat a scope amúgy
        // visszaadná, és egy globális találat vagy 500-at okozna, vagy elnyelné a tenant
        // saját tiltását (ami az operátori feloldás után nyomtalanul eltűnne).
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
                'source' => 'api:'.$key->prefix,
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

    /** @return CursorPaginator<Suppression> */
    public function list(ApiKey $key, int $perPage = 100): CursorPaginator
    {
        $this->authorize($key, 'suppressions:read');

        // Lapozás KÖTELEZŐ: a tábla természeténél fogva milliós, a teljes betöltés
        // memóriára futna — és nyílt e-mail-címeket öntene egy válaszba.
        return Suppression::query()
            ->orderByDesc('id')
            ->cursorPaginate(min($perPage, 1000));
    }

    public function remove(ApiKey $key, string $email): bool
    {
        $this->authorize($key, 'suppressions:write');

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
