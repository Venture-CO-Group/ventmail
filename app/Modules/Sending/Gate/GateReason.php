<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate;

/**
 * Strukturált elutasítási kódok (spec 6.2).
 *
 * Miért enum és nem sztring: ezek a kódok megjelennek az API-válaszban, a webhookban, az
 * üzenet-trace-ben és az auditban — egy elgépelt sztring csendben törné az integrációkat.
 */
enum GateReason: string
{
    case DomainUnverified = 'DOMAIN_UNVERIFIED';
    case Suppressed = 'SUPPRESSED';
    case NoConsent = 'NO_CONSENT';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case RateLimited = 'RATE_LIMITED';
    case ValidationRisk = 'VALIDATION_RISK';

    /** Seed-mód / allowlist: az éles küldés még nincs kinyitva erre a címzettre. */
    case SendingRestricted = 'SENDING_RESTRICTED';

    /** A csatorna (marketing, campaign, journey) a platformon ki van kapcsolva. */
    case ChannelDisabled = 'CHANNEL_DISABLED';

    /** Bemenet-épség: ismeretlen csatorna, hiányzó/érvénytelen címzett, tiltott fejléc. */
    case InvalidRequest = 'INVALID_REQUEST';

    /**
     * Egy kapu HIBÁRA futott (pl. a kvóta-szolgáltatás nem elérhető).
     * A lánc ilyenkor fail-closed: inkább nem küldünk, mint ellenőrizetlenül.
     */
    case GateError = 'GATE_ERROR';

    /**
     * Ugyanaz az Idempotency-Key, de az eredeti kérés még nem jutott döntésig.
     * Nem „elfogadva" — a hívónak tudnia kell, hogy nincs végleges válasz.
     */
    case InProgress = 'IN_PROGRESS';

    /**
     * H123 · a csatolmány-bájtok leírása nem sikerült (a tranzakció visszagördült). Átmeneti:
     * a kulcs felszabadul, ugyanazzal a kulccsal újrapróbálható.
     */
    case AttachmentStoreFailed = 'ATTACHMENT_STORE_FAILED';

    /**
     * H123 · a workspace kiküldésre váró csatolmány-bájtjai elérték a felső korlátot
     * (`SENDING_ATTACHMENT_MAX_PENDING_BYTES`). Átmeneti: a sor kiürülésével újra mehet.
     */
    case AttachmentPendingLimit = 'ATTACHMENT_PENDING_LIMIT';

    /** Ugyanaz az Idempotency-Key MÁS kérés-tartalommal. */
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';

    /**
     * ÁTMENETI-e az elutasítás: a kérés VÁLTOZATLANUL is megváltozhat a kimenete (kvóta, rate
     * limit, allowlist/seed-mód, csatorna-kapcsoló, domain-hitelesítés, kapu-hiba). Ilyenkor az
     * Idempotency-Key nem köthető le az elutasításhoz, különben a determinisztikus kulccsal
     * újrapróbáló kliens örökre ugyanazt a 422-t kapná. A kérés-függő okok (suppression,
     * consent, bemenet, kockázat) végleges elutasítások, és visszajátszódnak.
     */
    public function isTransient(): bool
    {
        return in_array($this, [
            self::DomainUnverified, self::QuotaExceeded, self::RateLimited,
            self::SendingRestricted, self::ChannelDisabled, self::GateError,
            self::AttachmentStoreFailed, self::AttachmentPendingLimit,
        ], true);
    }
}
