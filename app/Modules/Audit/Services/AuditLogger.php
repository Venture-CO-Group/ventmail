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
        $actor = Auth::user();

        return AuditLog::create([
            'workspace_id' => $this->context->hasWorkspace() ? $this->context->workspaceId() : null,
            'user_id' => $actor?->getKey(),
            // Denormalizált pillanatkép: a felhasználó törlése után is azonosítható marad,
            // ki végezte a műveletet (az FK `null on delete` egyébként kiürítené).
            'actor_email' => $actor?->email,
            'actor_sso_subject' => $actor?->sso_subject,
            'action' => $action,
            'auditable_type' => $auditable !== null ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'context' => $this->redact($context),
            'ip_hash' => $this->hashedIp(),
            // mb_substr: a bájt szerinti vágás többbájtos karakter közepén érvénytelen
            // UTF-8-at adna, amivel a Postgres az EGÉSZ admin-műveletet elszalasztaná.
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 255),
        ]);
    }

    /**
     * REKURZÍV titok-szűrés. A `context` paraméter épp arra való, hogy a hívó kérés-payloadot
     * adjon át — egy csak felső szintű szűrés a beágyazott jelszót átengedné, és mivel az
     * audit-sor sosem törölhető, a titok utólag nem is lenne kitakarítható.
     *
     * A kulcs NEM tűnik el, csak az érték cserélődik `[redacted]`-re: az auditnak a változás
     * TÉNYÉT mutatnia kell (pl. hogy a DKIM privát kulcs cserélődött), csak az értéket nem.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        $redacted = [];

        foreach ($attributes as $key => $value) {
            if ($this->isSecretKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    private function isSecretKey(string $key): bool
    {
        $name = strtolower($key);

        foreach (self::REDACTED as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hashedIp(): ?string
    {
        $ip = Request::ip();

        if ($ip === null) {
            return null;
        }

        // A hash kulcsolt: a nyers IP-t szótár-támadással sem lehet visszafejteni.
        // A pepper KÜLÖN titok — az APP_KEY rotációja különben minden korábbi hasht
        // összehasonlíthatatlanná tenne.
        return hash_hmac('sha256', $ip, (string) config('audit.hash_pepper'));
    }
}
