<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Az audit-sorok egyetlen írási pontja.
 *
 * Két dolgot garantál:
 * 1. Titok SOHA nem kerül a naplóba (a redact-lista mezőnév-alapú, nem tartalom-alapú).
 * 2. A forrás IP csak hashelve — a nyers cím maga is személyes adat.
 */
final class AuditLogger
{
    /**
     * Mezőnevek, amelyek értéke sosem kerül a naplóba. A hasonlóan képzett neveket is
     * fogja (pl. `dkim_private_key`, `smtp_password`, `api_key_hash`).
     */
    private const REDACTED = [
        'password', 'secret', 'token', 'key_hash', 'private_key', 'api_key', 'client_secret',
    ];

    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context
     */
    public function log(
        string $action,
        ?object $auditable = null,
        array $before = [],
        array $after = [],
        array $context = [],
    ): AuditLog {
        return AuditLog::create([
            'workspace_id' => $this->context->hasWorkspace() ? $this->context->workspaceId() : null,
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable !== null ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'context' => $this->redact($context),
            'ip_hash' => $this->hashedIp(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        return collect($attributes)
            ->reject(function ($value, $key) {
                $name = strtolower((string) $key);

                foreach (self::REDACTED as $needle) {
                    if (str_contains($name, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->all();
    }

    private function hashedIp(): ?string
    {
        $ip = Request::ip();

        if ($ip === null) {
            return null;
        }

        // A hash kulcsolt: a nyers IP-t szótár-támadással sem lehet visszafejteni.
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
