<?php

declare(strict_types=1);

namespace App\Modules\Sending\Enums;

/**
 * Küldési csatorna (spec 7., golden rule 4).
 *
 * Miért enum és miért nem szabad sztring: a `channel` korábban szabad szöveg volt, és a
 * kapuk pontos egyezést néztek. Egy `promo` vagy `Marketing` érték így a ConsentGate-en
 * „nem marketing" címen átment, a SuppressionGate pedig az `applies_to='marketing'` sort
 * nem is nézte — vagyis leiratkozott címre ment ki marketing levél, mind a hat kapu zöld
 * jelzése mellett.
 *
 * A `transactional` az EGYETLEN consent-mentes csatorna. Minden más marketing-hatókörű.
 */
enum Channel: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
    case Journey = 'journey';
    case Campaign = 'campaign';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /** Consent-köteles-e? Minden, ami nem tranzakciós. */
    public function requiresConsent(): bool
    {
        return $this !== self::Transactional;
    }

    /**
     * A suppression-hatókör, amit ez a csatorna elér.
     *
     * A journey és a campaign is MARKETING hatókörű: aki leiratkozott a hírlevélről, az a
     * journey-ből és a kampányból is leiratkozott.
     */
    public function suppressionScope(): string
    {
        return $this->requiresConsent() ? 'marketing' : 'transactional';
    }

    /**
     * A CONSENT HATÓKÖRE, amit ez a csatorna igényel.
     *
     * A hozzájárulást a címzett a MARKETINGRE adja, nem csatornánként: aki feliratkozott a
     * hírlevélre, annak a `campaign` és a `journey` levél is mehet — ugyanaz a jogalap.
     *
     * Ez nem elméleti finomság. A `ConsentGate` a csatorna NEVÉVEL kérdezte a
     * consent-táblát, ahol a sorok `channel = 'marketing'`-gel állnak: a `campaign` csatorna
     * így SOHA nem talált egyetlen consentet sem, tehát a B2 EGYETLEN kampányt sem tudott
     * volna kiküldeni (mérve: mind a három címzett `NO_CONSENT`-tel esett ki, a
     * megerősített consentjük ellenére).
     *
     * A `suppressionScope()`-pal ma megegyezik az érték, de a KÉT FOGALOM külön: a
     * suppression azt mondja meg, mit tilt egy tiltás, ez pedig azt, milyen jogalapot kér a
     * csatorna. Ha egyszer szétválnak, két külön metódus válik szét, nem egy elágazás.
     */
    public function consentScope(): string
    {
        return $this->requiresConsent() ? 'marketing' : 'transactional';
    }

    /**
     * A POOL-OSZTÁLY: melyik IP-poolon (és élesben melyik MTA-példányon) mehet ki.
     *
     * Csak két osztály van, és a kettő SOHA nem keveredik (deploy-ovh, kemény szabály 5):
     * a tranzakciós IP reputációja nem viselheti egy kampány spam-jelöléseit.
     */
    public function poolClass(): string
    {
        return $this === self::Transactional ? 'transactional' : 'marketing';
    }

    /** A sor prioritása (spec 7.): transactional > journey > campaign. */
    public function queuePriority(): int
    {
        return match ($this) {
            self::Transactional => 0,
            self::Journey => 1,
            self::Marketing, self::Campaign => 2,
        };
    }
}
