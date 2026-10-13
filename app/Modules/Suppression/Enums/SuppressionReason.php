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

    /**
     * A felület KÜLÖN LISTÁI (v1.1 · 7. modul): bounce-ok, panaszok, leiratkozások, kézi
     * tiltások. A `blocked` (fogadó-oldali tiltás) a bounce-okhoz, a `policy` (platform-
     * szabály) a kézi tiltásokhoz tartozik.
     *
     * @return array<string, list<string>>
     */
    public static function categories(): array
    {
        return [
            'bounces' => [self::Bounce->value, self::Blocked->value],
            'complaints' => [self::Complaint->value],
            'unsubscribes' => [self::Unsubscribe->value],
            'manual' => [self::Manual->value, self::Policy->value],
        ];
    }

    /**
     * A TENANT által (felületen vagy `/v1`-en) felvehető okok (v1.1 review B1, D186).
     *
     * A `policy` platform-operátori ok: tenantból felvéve egy panaszt is „felülírhatna".
     *
     * @return list<string>
     */
    public static function tenantWritable(): array
    {
        return [self::Manual->value, self::Bounce->value, self::Blocked->value, self::Unsubscribe->value, self::Complaint->value];
    }

    /**
     * Az új ok egy MEGLÉVŐ sor helyén (D186).
     *
     * A visszavonhatatlan ok (panasz, leiratkozás) RAGADÓS: más ok nem írhatja felül —
     * különben a „súlyosabb" bounce/policy felülírás után a sor törölhetővé válna, és a
     * leiratkozott cím újra levelet kapna. Visszavonhatatlan bejövő ok pedig mindig nyer egy
     * visszavonhatóval szemben. Két visszavonhatatlan közül a súlyosabb marad (panasz).
     */
    public static function merged(?self $existing, self $incoming): self
    {
        if ($existing === null) {
            return $incoming;
        }

        $existingSticky = $existing->isIrrevocableByTenant();
        $incomingSticky = $incoming->isIrrevocableByTenant();

        if ($existingSticky !== $incomingSticky) {
            return $existingSticky ? $existing : $incoming;
        }

        return $incoming->severity() > $existing->severity() ? $incoming : $existing;
    }

    /**
     * A REGI tárolt bounce-értékek (D205/D206): a v1.1 előtti automatikus tiltás
     * `hard_bounce`/`soft_bounce`-ot írt, ami nincs az enumban. Adat-migráció NINCS
     * (expand-only, élő adat nem íródik át) — OLVASÁSKOR `bounce`-ként kezeljük.
     */
    public const LEGACY_BOUNCE = ['hard_bounce', 'soft_bounce'];

    /**
     * A legacy tárolt ok `detail`-je (m7 polish, D273): a v1.1 előtti `hard_bounce` / `soft_bounce`
     * a hard/soft jelleget magában az okban hordozta. A felület, a `/v1` és a GDPR-export EGY helyről
     * képezi le (korábban két controllerben duplikálva).
     */
    public static function legacyDetail(?string $storedReason): ?string
    {
        return match ($storedReason) {
            'hard_bounce' => 'hard',
            'soft_bounce' => 'soft',
            default => null,
        };
    }

    /** A tárolt érték → enum, a legacy bounce-alias feloldásával. */
    public static function fromStored(?string $value): ?self
    {
        if ($value !== null && in_array($value, self::LEGACY_BOUNCE, true)) {
            return self::Bounce;
        }

        return self::tryFrom((string) $value);
    }

    /**
     * Szűréshez: az okok TÁROLT alakjai (a bounce a legacy értékeivel együtt).
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    public static function storedValues(array $reasons): array
    {
        return in_array(self::Bounce->value, $reasons, true)
            ? array_values(array_unique([...$reasons, ...self::LEGACY_BOUNCE]))
            : $reasons;
    }
}
