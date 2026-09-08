<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Models\Consent;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Services\DomainNormalizer;

/**
 * A PreSendGate 3. kapuja VALÓDI adatból (51. kritérium).
 *
 * Ez váltja le a `FakeConsentChecker`-t: az M0-3 óta a kapu egy in-memory tömböt kérdezett,
 * tehát élesben minden marketing levél átment volna. A kontraktus ugyanaz — a csere CSAK
 * kötés (`ConsentServiceProvider`), ahogy a golden rule-ok előírják.
 *
 * ## Miért TÖMEGES a lekérdezés
 *
 * Egy 1000 címzettes batch kapujában a címzettenkénti lekérdezés 1000 körutazás lenne,
 * szinkron a kérésben. Egyetlen `whereIn` megy — és a VÁLASZ a HIÁNYZÓ címek listája, nem a
 * megtaláltaké: a kapunak azt kell tudnia, kinek NINCS jogalapja.
 *
 * ## Fail-closed
 *
 * Ami nincs a `usable` halmazban, az NINCS megerősítve — ismeretlen cím, `pending`,
 * visszavont, lejárt: mind ugyanaz a válasz. A „nem találtam, tehát valószínűleg rendben"
 * pont az a gondolat, ami miatt a marketing consent nélkül kimegy.
 */
final class DatabaseConsentChecker implements ConsentChecker
{
    public function hasConfirmedConsent(string $email, string $channel): bool
    {
        return $this->withoutConsent([$email], $channel) === [];
    }

    public function withoutConsent(array $emails, string $channel): array
    {
        // A TRANZAKCIÓS csatorna nem consent-köteles — de ezt a kapu dönti el (`ConsentGate`
        // a `requiresConsent()`-tel), nem mi. Ha mégis ideér, a kérdés érdemi.
        $normalized = [];

        foreach ($emails as $email) {
            $key = DomainNormalizer::listKey(trim((string) $email));

            if ($key !== '') {
                $normalized[$key] = true;
            }
        }

        if ($normalized === []) {
            return [];
        }

        $confirmed = Consent::query()
            ->usable()
            ->where('channel', $channel)
            ->whereIn('email', array_keys($normalized))
            ->pluck('email')
            ->all();

        foreach ($confirmed as $email) {
            unset($normalized[(string) $email]);
        }

        // A NORMALIZÁLT alakot adjuk vissza, nem a hívó nyers bemenetét: a kapu ezt csak
        // megszámolja (nyers cím SOHA nem kerül a törölhetetlen auditba), a naplózásnál
        // pedig a normalizált alak az, ami a suppression- és consent-táblával egyezik.
        return array_keys($normalized);
    }
}
