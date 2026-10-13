<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * A FELMÉRÉS-SOR KAPUJA (D71): egyidejű (sorban álló + futó) felmérések számlálói — workspace-
 * enként, tulajdonosonként (user) és globálisan.
 *
 * ## Miért számláló, és miért nem az adatbázis-sor
 *
 * A felmérés a `imports-inspect` queue-ra megy (drága, de korlátos munka); ha a „van-e már futó?”
 * kérdést a rekordok korából döntenénk el, a VÁRAKOZÓ job lejárna a számításból, és a backlog
 * korlátlanul nőne — más tenantok felmérése éhezne. A számláló a sorban állót is számolja,
 * életkortól függetlenül; a TTL csak az elveszett job maradványát takarítja.
 *
 * A foglalás (`acquire`) a feltöltés ELEJÉN történik, a tárolás és az őrök előtt, és a job végén
 * (siker, hiba, worker-kill) szabadul. „Foglalt” válasz = a queue-mélység védelme.
 */
final class InspectGate
{
    public function acquire(int $workspaceId, ?int $userId): bool
    {
        try {
            return Cache::lock('import-inspect-gate', 5)->block(3, function () use ($workspaceId, $userId): bool {
                $keys = $this->keys($workspaceId, $userId);

                foreach ($keys as $key => $cap) {
                    if ((int) Cache::get($key, 0) >= $cap) {
                        return false;
                    }
                }

                foreach (array_keys($keys) as $key) {
                    Cache::put($key, (int) Cache::get($key, 0) + 1, (int) config('audience.import.inspect_gate_ttl_seconds'));
                }

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    public function release(int $workspaceId, ?int $userId): void
    {
        try {
            Cache::lock('import-inspect-gate', 5)->block(3, function () use ($workspaceId, $userId): void {
                foreach (array_keys($this->keys($workspaceId, $userId)) as $key) {
                    $value = max(0, (int) Cache::get($key, 0) - 1);

                    $value === 0
                        ? Cache::forget($key)
                        : Cache::put($key, $value, (int) config('audience.import.inspect_gate_ttl_seconds'));
                }
            });
        } catch (LockTimeoutException) {
            // a TTL úgyis felszabadítja
        }
    }

    /** @return array<string, int> számláló-kulcs → plafon */
    private function keys(int $workspaceId, ?int $userId): array
    {
        $keys = [
            'import-inspect:ws:'.$workspaceId => (int) config('audience.import.inspect_max_per_workspace'),
            'import-inspect:global' => (int) config('audience.import.inspect_max_global'),
        ];

        if ($userId !== null) {
            $keys['import-inspect:user:'.$userId] = (int) config('audience.import.inspect_max_per_user');
        }

        return $keys;
    }
}
