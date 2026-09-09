<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Sending\Services\DomainNormalizer;
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
     * „EZ A MŰVELET EGYETLEN TENANTHOZ SEM TARTOZIK" — explicit platform-hatókör.
     *
     * Miért kell sentinel a `null` helyett: a `$workspaceId = null` azt jelenti, hogy „nem
     * adtam meg, vedd a kontextusból". A platform-szintű olvasásnál (audit-kereső,
     * cluster-health) épp az a helyes, hogy SEMMILYEN tenanthoz ne tartozzon — a
     * kontextusból örökölt érték mérve egy VÉLETLEN tenant naplójába vitte a tenanteken
     * átnyúló keresés nyomát, ahol se nem az ő ügye, se nem kereshető vissza.
     */
    public const NO_WORKSPACE = -1;

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
     * @param  int|null  $workspaceId  EXPLICIT workspace — a platform-műveletekhez (C2).
     *
     * ## Miért lehet explicit a workspace
     *
     * Mert a platform-admin TENANTEKEN ÁTNYÚLVA dolgozik: nincs workspace-kontextusa,
     * miközben a művelete EGY KONKRÉT tenantot érint (impersonation, plan-override).
     * A kontextusból vett `null` azt jelentené, hogy a művelet a tenant naplójában NEM
     * jelenik meg — pedig épp neki kell látnia, hogy egy idegen ember megnyitotta a
     * fiókját. Ez az ő ügye, nem a mi belső naplónk.
     *
     * A paraméter NEM ad új jogot: a hívó (a `Impersonation`, a `PlanOverrides`) már
     * ellenőrizte a `platform.admin` képességet, és a `workspace_id` csak azt mondja meg,
     * KINEK a naplójába tartozik a sor.
     * @param  User|null  $actor  EXPLICIT aktor — akire a HÍVÓ jogosultságot ellenőrzött.
     *
     * ## Miért nem elég az `Auth::user()`
     *
     * A platform-szolgáltatások (C2) `User $admin` paramétert kapnak, és ARRA kapuznak
     * (`Gate::forUser($admin)`). Az `Auth::user()` ettől kétféleképpen csúszhat el, és
     * mindkettőt mértük: (1) munkamenet nélküli futásnál (konzol-command, queue-worker,
     * artisan) az aktor `null`, tehát a rendszer LEGERŐSEBB műveletei név nélkül kerülnek
     * a naplóba; (2) ha más van bejelentkezve, mint akit a gate engedett, a napló a ROSSZ
     * embert nevezi meg — és az `AuditSearch` `actor_email` szűrője pont ezeket a
     * műveleteket nem találja meg.
     *
     * Ezért: ahol a jogosultság-ellenőrzés explicit `User`-re megy, az audit is arra megy.
     * A visszaesés az `Auth::user()`-re a HTTP-utak kényelme marad.
     */
    public function log(
        string $action,
        ?object $auditable = null,
        array $before = [],
        array $after = [],
        array $context = [],
        ?int $workspaceId = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'workspace_id' => match (true) {
                $workspaceId === self::NO_WORKSPACE => null,
                $workspaceId !== null => $workspaceId,
                $this->context->hasWorkspace() => $this->context->workspaceId(),
                default => null,
            },
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
            /*
             | A KULCS IS PII LEHET.
             |
             | A tipikus alak egy `cím => állapot` map (`{"anna@example.com": "bounce"}`),
             | ahol a személyes adat a KULCSBAN van — a security-review megmérte, hogy az
             | ilyen kulcsok nyersen bekerültek a soha nem módosítható táblába.
             */
            $key = is_string($key) ? $this->pseudonymiseEmails($key) : $key;

            if ($this->isSecretKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            // A SAJÁT KÜLDÉSI KONFIGURÁCIÓ nem címzett-adat: a feladó és a válasz-cím a
            // tenant beállítása, amihez domain-hitelesítési lánc tartozik. Hasholva egy
            // deliverability-vizsgálat nem tudná megállapítani, MELYIK feladóra állították
            // át a kampányt (mérve: a `from_email` váltás kiolvashatatlan lett).
            if (in_array(strtolower((string) $key), self::SENDER_CONFIG_KEYS, true) && is_string($value)) {
                $redacted[$key] = $value;

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

        return $this->pseudonymiseEmails($value);
    }

    /**
     * A CÍMZETT E-MAIL-CÍME SOHA NEM KERÜL NYERSEN AZ AUDIT-PAYLOADBA.
     *
     * (A `actor_email` oszlop KIVÉTEL, és szándékos: az a MŰVELET VÉGZŐJE — belső
     * felhasználó vagy platform-admin —, és az `AuditSearch` aktor-szűrője rá épül. Az
     * elszámoltathatóság enélkül nem működne: „ki tette" nem visszakereshető hashből.)
     *
     * Miért a choke pointon és nem a hívóknál: a szabály eddig HÍVÓI FEGYELEM volt („adj át
     * `email_hash`-t"), és a security-review meg is mérte, hogy a `context` szabad szöveges
     * mezőin (`note`, `recipient`) a nyers cím átment. Az audit-sor pedig SOHA nem
     * módosítható (DB-trigger), tehát egy egyszer bekerült cím utólag nem takarítható ki —
     * a hívói fegyelem egyetlen kihagyása véglegesen PII-t hagy egy törölhetetlen táblában.
     *
     * Miért pszeudonimizálás és nem `[redacted]`: a diagnózishoz KELL, hogy két sorról meg
     * lehessen állapítani, ugyanarról a címről szólnak-e. A hash ugyanaz a
     * `KeyedHash::of('email', …)`, amit a `suppressions.email_hash` és a `contacts.email_hash`
     * használ — vagyis a napló-sor összekapcsolható a tiltással, a nyers cím ismerete nélkül.
     * A prefix 16 hex karakter: olvasható, és a hash erejét nem ez adja, hanem a pepper.
     *
     * ## A DOMAIN MEGMARAD — és ez nem engedmény
     *
     * A helyi rész az, ami a személyt azonosítja; a domain viszont a DIAGNÓZIS. A minta
     * ugyanis nem tudja szétválasztani az ÉRINTETT címét a KONFIGURÁCIÓS célcímtől, és a
     * review meg is mérte, mit vitt el a teljes csere:
     *  - egy `InboundRoute` `forward` célja (`attacker@evil.com`) csak hash lett, tehát egy
     *    levél-exfiltráció CÉLJA nem volt visszakereshető — egy törölhetetlen táblában,
     *    utólag javíthatatlanul;
     *  - a DMARC `rua=mailto:` és a userinfo-s URL is: `https://user:titok@hook.pelda.hu/x`
     *    → a HOSZTNÉV tűnt el a naplóból.
     * A domain megtartásával mindhárom eset diagnosztizálható marad, az azonosító rész
     * viszont ugyanúgy nem szerepel. A hash TELJES normalizált címből készül, tehát a
     * `email_hash`-sel való összekapcsolhatóság sem sérül.
     */
    private function pseudonymiseEmails(string $value): string
    {
        if (! str_contains($value, '@')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/([\p{L}\p{N}._%+\-]+)@([\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+)/u',
            // A DOMAIN KISBETŰVEL megy tovább: a normalizált alak az, amit egy szűrő vagy
            // egy grep keres, és a `Pelda.HU` / `pelda.hu` kettősség csak zavar.
            static fn (array $matches): string => '[pii:email:'.substr(
                KeyedHash::of('email', DomainNormalizer::listKey($matches[0])), 0, 16
            ).']@'.mb_strtolower($matches[2]),
            $value,
        );
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

    /**
     * A TENANT SAJÁT KÜLDÉSI KONFIGURÁCIÓJA — nem címzett-PII, ezért nyersen marad.
     *
     * Ezek a mezők azt írják le, MI A MI OLDALUNK: melyik feladóval, melyik válasz-címmel
     * megy a levél. A címzett címe hasholódik, ezek viszont a diagnózis: egy visszaélés-
     * vagy kézbesíthetőségi vizsgálat épp azt kérdezi, melyik feladóra állították át a
     * kampányt vagy a listát.
     *
     * @var list<string>
     */
    private const SENDER_CONFIG_KEYS = [
        'from_email', 'from', 'sender', 'reply_to', 'return_path', 'envelope_from',
        'mx_target', 'actor_email',
    ];

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
