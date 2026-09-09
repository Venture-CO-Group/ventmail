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
        'password', 'passphrase', 'secret', 'token', 'key_hash', 'private_key', 'api_key',
        'client_secret', 'credential', 'authorization', 'cookie', 'bearer', 'signature',
        'refresh', 'code_verifier', 'authorization_code', 'nonce', 'otp', 'mfa',
        'recovery', 'salt', 'pepper', 'session',
    ];

    /**
     * Érték-alapú védőháló: egy ártatlan nevű mező is hordozhat kulcsot, a kulcsnév-alapú
     * szűrés önmagában nem elég.
     */
    private const SECRET_VALUE_PATTERNS = [
        '/^vm_(live|test)_/',           // ventMail API-kulcs
        '/-----BEGIN [A-Z ]*PRIVATE KEY/', // PEM privát kulcs
        '/^eyJ[A-Za-z0-9_-]+\./',        // JWT
        '/^sk_[A-Za-z0-9]/',             // idegen szolgáltatók titkos kulcsai
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
            'before' => $this->capped($this->redact($this->attributesFor($auditable, $before))),
            'after' => $this->capped($this->redact($this->attributesFor($auditable, $after))),
            'context' => $this->capped($this->redact($context)),
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
    private function redact(array $attributes, int $depth = 0): array
    {
        if ($depth >= (int) config('audit.max_depth')) {
            return ['_truncated_depth' => true];
        }

        $redacted = [];

        foreach ($attributes as $key => $value) {
            if ($this->isSecretKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = $this->redactValue($value, $depth);
        }

        return $redacted;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->redact($value, $depth + 1);
        }

        // Objektumot is be kell járni: egy DTO publikus property-je ugyanúgy tartalmazhat
        // jelszót, és az Eloquent `array` cast kiszerializálná.
        if (is_object($value)) {
            // Egy objektum skalárrá is szerializálódhat (pl. Carbon dátum) — ilyenkor
            // nincs mit bejárni, de a sztring-mintákat akkor is nézni kell.
            $decoded = json_decode((string) json_encode($value), true);

            return is_array($decoded)
                ? $this->redact($decoded, $depth + 1)
                : $this->redactValue($decoded, $depth);
        }

        if (is_string($value)) {
            return $this->redactString($value, $depth);
        }

        return $value;
    }

    private function redactString(string $value, int $depth): mixed
    {
        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return '[redacted]';
            }
        }

        // JSON-sztringbe ágyazott payload: a kérés-body nyers sztringként átadva minden
        // titkot átengedne.
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $this->redact($decoded, $depth + 1);
            }
        }

        return $value;
    }

    /**
     * A payload felső korlátja: egy MIME-fejlécekkel teli context korlátlan PII-t öntene
     * egy törölhetetlen táblába.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function capped(array $payload): array
    {
        $max = (int) config('audit.max_payload_bytes');

        if (strlen((string) json_encode($payload)) <= $max) {
            return $payload;
        }

        return ['_truncated' => true, '_keys' => array_keys($payload)];
    }

    /**
     * Modell-specifikus audit-kizárás: a `$auditRedacted` mezők (pl. a suppression nyílt
     * e-mail-címe) NEM kerülhetnek a soha nem törölhető audit-táblába.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributesFor(?object $auditable, array $attributes): array
    {
        $excluded = property_exists($auditable ?? new \stdClass, 'auditRedacted')
            ? (array) $auditable->auditRedacted
            : [];

        foreach ($excluded as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = '[redacted]';
            }
        }

        return $attributes;
    }

    /**
     * PONTOS EGYEZÉSSEL redaktált kulcsok — nem részsztringgel.
     *
     * A `state` az OAuth CSRF-tokenje, és RÖVID, KÖZÖNSÉGES szó: részsztringként bármibe
     * beletalál, ami „state"-et tartalmaz. A review megmérte, hogy a C1
     * `dunning_state`-jébe is beletalált, és a felfüggesztés/fizetés ténye a VÁLTOZÁS
     * ÉRTÉKE NÉLKÜL került az auditba (`{"dunning_state": "[redacted]"}` előtte és utána
     * is) — vagyis a legdurvább üzemeltetői művelet nyoma használhatatlan lett.
     *
     * @var list<string>
     */
    private const REDACTED_EXACT = ['state'];

    private function isSecretKey(string $key): bool
    {
        $name = strtolower($key);

        if (in_array($name, self::REDACTED_EXACT, true)) {
            return true;
        }

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
        return KeyedHash::of('ip', $ip);
    }
}
