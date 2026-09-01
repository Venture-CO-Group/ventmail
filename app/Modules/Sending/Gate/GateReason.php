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
}
