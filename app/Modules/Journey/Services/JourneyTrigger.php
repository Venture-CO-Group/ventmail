<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Consent\Models\Consent;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
    public function __construct(private readonly EntitlementChecker $entitlements) {}

    /**
     * BELÉPTETÉS a triggerre illeszkedő journey-kbe.
     *
     * @param  array<string, mixed>  $payload  a triggerhez tartozó adatok (a `context`-be kerül)
     * @return list<JourneyRun> a létrejött futások
     */
    /** Hány hamisítható (inbound) belépés fér bele naponta címenként, journey-nként. */
    private const SPOOFABLE_DAILY_LIMIT = 3;

    public function fire(TriggerType $type, string $email, array $payload = []): array
    {
        $email = DomainNormalizer::listKey(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        $runs = [];

        /*
         | A CÉLZOTT JOURNEY szűkítése — ha a hívó megnevezte.
         |
         | Ez a szűrő hiányzott, és mérve a következményt adta: az `InboundRoute`
         | `journey` akciója átadta a cél journey uuid-ját, de SENKI nem olvasta. Egy
         | „info@"-ra írt levél így a workspace ÖSSZES `inbound_email` triggerű journey-jét
         | elindította ugyanannak a címzettnek — mérve két futás egyetlen levélből, egy
         | egymással semmilyen kapcsolatban nem lévő journey-párral. Vagyis több
         | párhuzamos marketing-sorozat, egyetlen beérkező levélből.
         |
         | A `null`/üres cél továbbra is „minden ilyen triggerű journey" — a form-signup és
         | a tag-alapú triggereknek épp ez a helyes viselkedése.
         */
        $target = isset($payload['journey']) && is_string($payload['journey']) && $payload['journey'] !== ''
            ? $payload['journey']
            : null;

        $journeys = Journey::query()
            ->where('status', 'active')
            ->where('trigger_type', $type->value)
            ->when($target !== null, fn ($query) => $query->where('uuid', $target))
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
     * BELEFÉR-e még egy hamisítható belépés ebbe a journey-be erre a címre?
     *
     * A kulcs a CÍM LENYOMATA, nem a nyers cím: a rate-limiter tárolója (Redis) nem a mi
     * retenciónk alatt áll, és egy nyers címlista ott PII-adatbázis lenne.
     */
    private function withinSpoofableRate(Journey $journey, string $email): bool
    {
        $key = 'journey-spoofable:'.$journey->getKey().':'.KeyedHash::of('email', $email);

        // NAPI HÁROM: egy legitim „válaszolj a levelünkre" automatizmus ennél többet nem
        // kér ugyanattól az embertől, egy támadó pedig ennél kevesebbel nem tud kárt tenni.
        return RateLimiter::attempt($key, self::SPOOFABLE_DAILY_LIMIT, static fn () => true, 86400) !== false;
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
        // A `EntitlementChecker`-en, nem közvetlenül a ventPay-en: a SUB-ACCOUNT a PARENT
        // csomagját használja (C1 · 76.). A nyers `hasEntitlement($childId, 'journeys')`
        // egy alszámlánál olyan csomagot kérdezett, ami a childnak nincs.
        if (! $this->entitlements->allows($journey->workspace, Feature::Journeys)) {
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

        /*
         | 3c. ÜTEMKORLÁT a HAMISÍTHATÓ triggeren.
         |
         | A megerősített consent azt bizonyítja, hogy a CÍM feliratkozott — nem azt, hogy
         | EZ a levél tőle jött. Mérve: `allow_reentry` + 1 órás cooldown mellett 24
         | szimulált óra alatt 120 hamisított levél 24 belépést és 72 kiküldött levelet
         | adott az áldozat postafiókjába — a tenant hitelesített domainjéről, a tenant
         | DKIM-jével, a tenant kvótájából. A támadónak csak egy feliratkozó címét és a
         | tenant inbound címét kell tudnia.
         |
         | A korlát a CÍM és a JOURNEY párosára megy, nem a feladó IP-jére: az IP a
         | támadó választása, a cím viszont az áldozat — és épp őt védjük.
         */
        if ($journey->triggerType()->isSpoofable() && ! $this->withinSpoofableRate($journey, $email)) {
            Log::warning('Inbound-trigger elutasítva: ütemkorlát.', [
                'journey' => (string) $journey->uuid,
            ]);

            return null;
        }

        /*
         | 3b. A JOURNEY-NEK LEGYEN HOVA BELÉPNI.
         |
         | Egy `first_step_id = null` journey aktív lehet (a szerkesztő még nem tett bele
         | lépést), és a beléptetés korábban ilyenkor is létrejött: egy futás, aminek nincs
         | mit végrehajtania, viszont PERZISZTÁLJA a címzett CÍMÉT egy lépés nélküli
         | journey alatt. Az első tick azonnal lezárta volna — de a cím addig is ott van, és
         | a riportban egy „belépett, majd rögtön kilépett" sor keletkezik, aminek semmi
         | információtartalma nincs.
         */
        if ($journey->first_step_id === null) {
            return null;
        }

        // 4. ÚJRABELÉPÉS: alapból tilos. Egy „kosárelhagyás" journey-be a címzett naponta
        //    újra beléphetne, és minden belépés új levélsorozatot indítana.
        if (! $this->mayReenter($journey, $email)) {
            return null;
        }

        $contact = Contact::query()->where('email', $email)->first();

        try {
            $run = JourneyRun::query()->create([
                'journey_id' => $journey->getKey(),
                'contact_id' => $contact?->getKey(),
                'current_step_id' => $journey->first_step_id,
                'email' => $email,
                'status' => 'active',
                'context' => $payload,
                'entered_at' => now(),
            ]);

            /*
             | A BELÉPÉS-SZÁMLÁLÓ — enélkül a riport nullával oszt.
             |
             | A `journeys.entered_count` oszlop megvolt, de SENKI nem növelte: mérve
             | `entered_count = 0` egy `completed_count = 1` mellett. Vagyis a
             | journey-riport „belépés: 0 / befejezés: 1"-et mutatott, és minden konverziós
             | ráta (befejezés/belépés) nullával osztott. A `finish()` a kilépési oldalt
             | mindig növelte — a belépési oldal maradt ki.
             */
            $journey->increment('entered_count');

            return $run;
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
