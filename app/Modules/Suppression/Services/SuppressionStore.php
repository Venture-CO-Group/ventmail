<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Services;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * A suppression-lista egyetlen írási és olvasási pontja (spec 6.2, M0-4 #26).
 *
 * A scope-ellenőrzés ITT van, nem a controllerben: az SMTP relaynek (A2) és a
 * bounce-feldolgozónak (A2) nem lesz FormRequestje, viszont ugyanezen a szolgáltatáson
 * kell átmenniük.
 */
final class SuppressionStore
{
    /** A suppression sorrendje súly szerint: a panasz erősebb jelzés, mint a bounce. */
    private const SEVERITY = ['manual' => 1, 'unsubscribe' => 2, 'bounce' => 3, 'blocked' => 4, 'complaint' => 5, 'policy' => 6];

    public function add(ApiKey $key, string $email, string $reason, string $appliesTo = 'all'): Suppression
    {
        $this->authorize($key, 'suppressions:write');

        $email = strtolower(trim($email));

        $existing = Suppression::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Nem duplikálunk: a SÚLYOSABB ok marad meg. Egy panaszt nem írhat felül egy
            // későbbi soft bounce.
            if ($this->severity($reason) > $this->severity((string) $existing->reason)) {
                $existing->update(['reason' => $reason, 'applies_to' => $appliesTo]);
            }

            return $existing->fresh();
        }

        return Suppression::create([
            'email' => $email,
            'reason' => $reason,
            'applies_to' => $appliesTo,
            'source' => 'api:'.$key->prefix,
        ]);
    }

    /** @return Collection<int, Suppression> */
    public function list(ApiKey $key): Collection
    {
        $this->authorize($key, 'suppressions:read');

        // A global scope adja a workspace + platform sorokat; másik tenant sosem látszik.
        return Suppression::query()->orderByDesc('id')->get();
    }

    public function remove(ApiKey $key, string $email): bool
    {
        $this->authorize($key, 'suppressions:write');

        $suppression = Suppression::query()->where('email', strtolower(trim($email)))->first();

        if ($suppression === null) {
            return false;
        }

        // Platform-szintű (globális) tiltást egy tenant NEM vehet le — a modell guardja
        // ezt amúgy is dobná, de itt beszédes hibát adunk.
        if ($suppression->isPlatformWide()) {
            throw new AuthorizationException('A globális suppression csak operátori úton vonható vissza.');
        }

        return (bool) $suppression->delete();
    }

    private function authorize(ApiKey $key, string $scope): void
    {
        if (! $key->hasScope($scope)) {
            throw new AuthorizationException("Az API-kulcshoz hiányzik a(z) {$scope} scope.");
        }
    }

    private function severity(string $reason): int
    {
        return self::SEVERITY[$reason] ?? 0;
    }
}
