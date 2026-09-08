<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Consent\Models\Consent;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * A JOURNEY BELÉPTETÉS (69., 70., 71. kritérium).
 *
 * ## AZ ÖT TRIGGER, ÉS AMI KÖZÖTTÜK A KÜLÖNBSÉG
 *
 * Négy trigger a MI rendszerünkből jön: űrlap-beküldés, címkézés, szegmensbe lépés,
 * hitelesített API-hívás. A forrás bizonyított.
 *
 * Az ÖTÖDIK — az INBOUND E-MAIL (A4) — nem. Bárki írhat levelet a világon `anna@pelda.hu`
 * feladóval: az SMTP `MAIL FROM` és a `From:` fejléc is a KÜLDŐ szava. Ezért szól a 71.
 * kritérium arról, hogy az inbound trigger CSAK MEGERŐSÍTETT kontaktnál indíthat marketing
 * ágat — enélkül egy idegen bármikor levelet küldhetne Anna nevében, Anna hozzájárulása
 * nélkül, a MI domainünkről és a MI reputációnkkal.
 *
 * Ez nem elméleti: a spam-trap címekre küldött „iratkozz fel" levél a klasszikus
 * lista-mérgezés, és a kárt a közös IP-n minden ügyfél fizeti.
 */
final class JourneyTrigger
{
    public function __construct(private readonly VentPayClient $ventPay) {}

    /**
     * BELÉPTETÉS a triggerre illeszkedő journey-kbe.
     *
     * @param  array<string, mixed>  $payload  a triggerhez tartozó adatok (a `context`-be kerül)
     * @return list<JourneyRun> a létrejött futások
     */
    public function fire(TriggerType $type, string $email, array $payload = []): array
    {
        $email = DomainNormalizer::listKey(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        $runs = [];

        $journeys = Journey::query()
            ->where('status', 'active')
            ->where('trigger_type', $type->value)
            ->get();

        foreach ($journeys as $journey) {
            if (! $this->matchesConfig($journey, $payload)) {
                continue;
            }

            $run = $this->enter($journey, $email, $payload);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * EGY JOURNEY-be beléptetés — a kapukkal.
     */
    public function enter(Journey $journey, string $email, array $payload = []): ?JourneyRun
    {
        $email = DomainNormalizer::listKey(trim($email));

        /*
         | 1. ENTITLEMENT (70. kritérium).
         |
         | A `journeys` funkció a csomag része-e. Belépéskor ÉS futás közben is (a motor
         | minden lépés előtt újra megkérdezi): egy lefokozott csomag nem futtathat tovább
         | automatizálást hetekig.
         */
        if (! $this->ventPay->hasEntitlement((int) $journey->workspace_id, 'journeys')) {
            return null;
        }

        // 2. SUPPRESSION: aki leiratkozott, nem lép be. Nem csak a levél miatt — a
        //    címkézés és a szegmens-mozgatás is profilalkotás.
        $suppressed = Suppression::query()
            ->where('email', $email)
            ->get()
            ->contains(fn (Suppression $row) => $row->isActive());

        if ($suppressed) {
            return null;
        }

        /*
         | 3. A 71. KRITÉRIUM: HAMISÍTHATÓ TRIGGER csak MEGERŐSÍTETT consenttel.
         |
         | Az inbound e-mail feladója a küldő szava. Ha ez marketing-ágat indíthatna
         | megerősített hozzájárulás nélkül, egy idegen levelet küldhetne bárki nevében — a
         | mi domainünkről, a mi reputációnkkal.
         |
         | A `pending` consent NEM elég: az azt jelenti, hogy valaki (esetleg épp a támadó)
         | kérte a feliratkozást, de a címzett még nem hagyta jóvá.
         */
        if ($journey->triggerType()->isSpoofable() && ! $this->hasConfirmedConsent($email)) {
            Log::info('Inbound-trigger elutasítva: nincs megerősített consent.', [
                'journey' => (string) $journey->uuid,
            ]);

            return null;
        }

        // 4. ÚJRABELÉPÉS: alapból tilos. Egy „kosárelhagyás" journey-be a címzett naponta
        //    újra beléphetne, és minden belépés új levélsorozatot indítana.
        if (! $this->mayReenter($journey, $email)) {
            return null;
        }

        $contact = Contact::query()->where('email', $email)->first();

        try {
            return JourneyRun::query()->create([
                'journey_id' => $journey->getKey(),
                'contact_id' => $contact?->getKey(),
                'current_step_id' => $journey->first_step_id,
                'email' => $email,
                'status' => 'active',
                'context' => $payload,
                'entered_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             | A RÉSZLEGES EGYEDI INDEX fogta meg: már van AKTÍV futás.
             |
             | Ez nem hiba, hanem a védelem működése — két párhuzamos trigger (kétszer
             | beküldött űrlap) közül a második itt hasal el, tehát a kontakt nem kap két
             | párhuzamos levélsorozatot. Az adatbázis dönt, nem az alkalmazás
             | „megnéztem, nincs" ellenőrzése, ami két kérés között befér.
             */
            return null;
        }
    }

    /**
     * A TRIGGER-KONFIGURÁCIÓ illeszkedése.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchesConfig(Journey $journey, array $payload): bool
    {
        $config = (array) $journey->trigger_config;

        return match ($journey->triggerType()) {
            // Melyik ŰRLAP indítja? Ha nincs megadva, MINDEGYIK.
            TriggerType::FormSignup => $this->matchesKey($config, $payload, 'form'),
            TriggerType::TagAdded => $this->matchesKey($config, $payload, 'tag'),
            TriggerType::SegmentEntered => $this->matchesKey($config, $payload, 'segment'),
            TriggerType::ApiEvent => $this->matchesKey($config, $payload, 'event'),
            // Melyik INBOUND ROUTE-ra érkezett levél indítja?
            TriggerType::InboundEmail => $this->matchesKey($config, $payload, 'route'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function matchesKey(array $config, array $payload, string $key): bool
    {
        $expected = $config[$key] ?? null;

        // A HIÁNYZÓ szűrő MINDENT elfogad — de ezt ki kell mondani, mert a másik
        // értelmezés (semmit nem fogad el) csendben működésképtelen journey-t adna.
        if ($expected === null || $expected === '') {
            return true;
        }

        return (string) ($payload[$key] ?? '') === (string) $expected;
    }

    private function hasConfirmedConsent(string $email): bool
    {
        return Consent::query()
            ->usable()
            ->where('email', $email)
            ->where('channel', 'marketing')
            ->exists();
    }

    private function mayReenter(Journey $journey, string $email): bool
    {
        $previous = JourneyRun::query()
            ->where('journey_id', $journey->getKey())
            ->where('email', $email)
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return true;
        }

        // AKTÍV futás mellett SOHA nincs újrabelépés (a részleges egyedi index is ezt
        // védi) — a `allow_reentry` a BEFEJEZETT futás utáni visszatérésről szól.
        if (! $previous->isFinished()) {
            return false;
        }

        if (! $journey->allow_reentry) {
            return false;
        }

        $cooldown = max(1, (int) $journey->reentry_cooldown_hours);

        return $previous->finished_at === null
            || $previous->finished_at->addHours($cooldown)->lessThanOrEqualTo(now());
    }
}
