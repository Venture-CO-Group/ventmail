<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Enums;

/**
 * A tiltás oka és SÚLYA (spec 7.).
 *
 * Miért enum: szabad sztringgel egy elgépelt `reason` súlya 0 lenne (sosem írhatna felül
 * semmit), és foglalná az unique kulcsot — a tiltás létezne, de nem tiltana.
 */
enum SuppressionReason: string
{
    case Manual = 'manual';
    case Unsubscribe = 'unsubscribe';
    case Bounce = 'bounce';
    case Blocked = 'blocked';
    case Complaint = 'complaint';
    case Policy = 'policy';

    public function severity(): int
    {
        return match ($this) {
            self::Manual => 1,
            self::Unsubscribe => 2,
            self::Bounce => 3,
            self::Blocked => 4,
            self::Complaint => 5,
            self::Policy => 6,
        };
    }

    /**
     * Az érintett kifejezett akaratát vagy panaszát rögzítő ok — ezt a tenant NEM
     * vonhatja vissza API-ból, különben újra küldhetne a panaszkodónak.
     */
    public function isIrrevocableByTenant(): bool
    {
        return in_array($this, [self::Complaint, self::Unsubscribe], true);
    }

    /** A permanens okok törlik a lejáratot: egy panasz nem évül el holnap. */
    public function isPermanent(): bool
    {
        return in_array($this, [self::Complaint, self::Policy, self::Blocked], true);
    }
}
