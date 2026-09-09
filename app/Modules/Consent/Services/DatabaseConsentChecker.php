<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Models\Consent;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Enums\Channel;
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
 *
 * És ez az ÉRTELMEZHETETLEN bemenetre is igaz: az üres sztring, a whitespace és a nem-skalár
 * elem is „nincs consentje". Korábban ezek csendben kiestek, és az üres bemenetre a metódus
 * üres listát adott — vagyis `hasConfirmedConsent('')` IGAZAT mondott. A `SendMessage` úton
 * ma egy korábbi kapu megfogja az üres címzettet, de egy kontraktus alapértelmezése nem
 * lehet „nincs kifogás".
 */
final class DatabaseConsentChecker implements ConsentChecker
{
    public function hasConfirmedConsent(string $email, string $channel): bool
    {
        return $this->withoutConsent([$email], $channel) === [];
    }

    public function withoutConsent(array $emails, string $channel): array
    {
        /*
         | A CSATORNÁT A CONSENT HATÓKÖRÉRE fordítjuk.
         |
         | A hozzájárulást a címzett a MARKETINGRE adja, nem csatornánként: aki feliratkozott
         | a hírlevélre, annak a `campaign` és a `journey` levél is mehet — ugyanaz a jogalap.
         |
         | Enélkül a `campaign` csatorna a `channel = 'campaign'` sorokat kereste a
         | consent-táblában, ahol SOHA nincs ilyen: mérve, mind a három megerősített
         | consenttel bíró címzett `NO_CONSENT`-tel esett ki, vagyis a B2 egyetlen kampányt
         | sem tudott volna kiküldeni. Az ISMERETLEN csatorna a `marketing` hatókört kapja —
         | fail-closed, mert a szigorúbb jogalapot kérni biztonságos, a lazábbat nem.
         */
        $scope = (Channel::tryFrom($channel) ?? Channel::Marketing)->consentScope();

        // A TRANZAKCIÓS csatorna nem consent-köteles — de ezt a kapu dönti el (`ConsentGate`
        // a `requiresConsent()`-tel), nem mi. Ha mégis ideér, a kérdés érdemi.
        $normalized = [];
        $unusable = [];

        foreach ($emails as $email) {
            // A NEM SZTRING elem korábban `ErrorException`-t adott a `(string)` caston
            // (tömb bemenetnél): a kapu belső hibája a hívónak újrapróbálható 5xx, nem
            // elutasítás. Itt egyszerűen ÉRTELMEZHETETLEN — tehát nincs consentje.
            $key = is_scalar($email) ? DomainNormalizer::listKey(trim((string) $email)) : '';

            if ($key === '') {
                // FAIL-CLOSED. Az üres/értelmezhetetlen elem korábban CSENDBEN kiesett, és
                // az üres bemenetre a metódus `[]`-t adott — vagyis
                // `hasConfirmedConsent('')` IGAZAT mondott. A `SendMessage` úton ma egy
                // korábbi kapu megfogja, de a KONTRAKTUS alapértelmezése nem lehet
                // „nincs kifogás".
                $unusable[] = is_scalar($email) ? (string) $email : '(értelmezhetetlen)';

                continue;
            }

            $normalized[$key] = true;
        }

        if ($normalized === []) {
            return $unusable;
        }

        $confirmed = Consent::query()
            ->usable()
            ->where('channel', $scope)
            ->whereIn('email', array_keys($normalized))
            ->pluck('email')
            ->all();

        foreach ($confirmed as $email) {
            unset($normalized[(string) $email]);
        }

        // A NORMALIZÁLT alakot adjuk vissza, nem a hívó nyers bemenetét: a kapu ezt csak
        // megszámolja (nyers cím SOHA nem kerül a törölhetetlen auditba), a naplózásnál
        // pedig a normalizált alak az, ami a suppression- és consent-táblával egyezik.
        return array_values(array_merge($unusable, array_keys($normalized)));
    }
}
