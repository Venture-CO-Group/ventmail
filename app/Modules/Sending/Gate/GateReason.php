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

    /** Ugyanaz az Idempotency-Key MÁS kérés-tartalommal. */
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
}
