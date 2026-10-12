<?php

declare(strict_types=1);

namespace App\Modules\Audience\Enums;

/**
 * Az import JOGALAPJA — az a válasz, amit a felhasználónak kötelező megadnia (v1.1, kemény
 * szabály 2). Három érték, mert három döntést vált ki:
 *
 *  - `verified_consent`: dokumentált double opt-in vagy írásos hozzájárulás. CSAK ez (forrással
 *    és dátummal) ad `subscribed` státuszt;
 *  - `legitimate_interest`: meglévő ügyfélkapcsolat / szerződés — jogos, de NEM marketing-
 *    hozzájárulás, ezért `pending` (re-permission szükséges);
 *  - `unknown`: nem igazolt vagy ismeretlen eredet — `pending`, re-permission kampány kell.
 */
enum ImportLegalBasis: string
{
    case VerifiedConsent = 'verified_consent';
    case LegitimateInterest = 'legitimate_interest';
    case Unknown = 'unknown';

    /** Az érték, amit a `consents`/`consent_events` `legal_basis` oszlopa kap. */
    public function evidenceBasis(): string
    {
        return match ($this) {
            self::VerifiedConsent => 'consent',
            self::LegitimateInterest => 'legitimate_interest',
            self::Unknown => 'unknown',
        };
    }

    /** Kell-e forrás és dátum? Az `unknown` pont azt mondja: nincs mit megadni. */
    public function requiresEvidence(): bool
    {
        return $this !== self::Unknown;
    }

    /**
     * Az eredmény-státusz — EGYETLEN helyen eldöntve, hogy a „subscribed" feltétele ne
     * szóródjon szét: igazolt hozzájárulás ÉS megadott forrás ÉS dátum.
     */
    public function resultingStatus(?string $source, mixed $date): string
    {
        return $this === self::VerifiedConsent && trim((string) $source) !== '' && $date !== null
            ? 'subscribed'
            : 'pending';
    }
}
