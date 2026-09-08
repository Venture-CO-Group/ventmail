<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * A KAMPÁNY ÁLLAPOTGÉPE (62. kritérium) — az egyetlen hely, ahol az állapot változik.
 *
 * Miért nem a modellben, `forceFill`-ekkel szétszórva: az átmenetek FELTÉTELEI nem csak
 * állapotból állnak. Az ütemezéshez kell jövőbeli időpont, az indításhoz kell sablon és
 * célzás, a folytatáshoz kell, hogy legyen még kinek küldeni. Ha ez a hívási helyeken
 * lenne, minden új belépési pont (API, felület, ütemező, journey) újra megírná — és
 * valamelyik kihagyna egy feltételt.
 *
 * A modell `updating` hookja ezt KIEGÉSZÍTI: a célzás és a sablon csak szerkeszthető
 * állapotban változhat. A kettő együtt zárja a kört.
 */
final class CampaignLifecycle
{
    public function __construct(private readonly AudienceResolver $audience) {}

    /** ÜTEMEZÉS — jövőbeli időpontra, a tenant időzónájában értelmezve. */
    public function schedule(Campaign $campaign, string $localTime, string $timezone): Campaign
    {
        $this->assertReady($campaign);

        // AZ IDŐZÓNA ELLENŐRZÉSE ITT: egy elgépelt zóna (`Europe/Budapesst`) különben
        // kivételt dobna a `Carbon`-ban, és a felhasználó egy nyers hibát látna.
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("Ismeretlen időzóna: {$timezone}.");
        }

        $at = Carbon::parse($localTime, $timezone);

        // A JÖVŐBELI IDŐPONT követelménye nem formalitás: a múltra ütemezett kampányt az
        // ütemező AZONNAL esedékesnek látná, és a felhasználó azt hinné, hogy még van
        // ideje szerkeszteni.
        if ($at->isPast()) {
            throw new InvalidArgumentException(
                'Az ütemezett időpont a jövőben kell legyen. Azonnali küldéshez indítsd el a kampányt.'
            );
        }

        $this->transition($campaign, CampaignStatus::Scheduled);

        // A `scheduled_at` UTC-ben (a `timestamptz` amúgy is azt tesz), a `timezone` a
        // MEGJELENÍTÉSHEZ és a nyári időszámítás miatti újraszámításhoz.
        $campaign->forceFill([
            'scheduled_at' => $at->utc(),
            'timezone' => $timezone,
        ])->save();

        return $campaign->fresh();
    }

    /** VISSZAVÉTEL vázlatba — a felhasználó még szerkeszt. */
    public function unschedule(Campaign $campaign): Campaign
    {
        $this->transition($campaign, CampaignStatus::Draft);

        $campaign->forceFill(['scheduled_at' => null])->save();

        return $campaign->fresh();
    }

    /** INDÍTÁS. A címzett-halmaz materializálása a `StartCampaign` action dolga. */
    public function start(Campaign $campaign): Campaign
    {
        $this->assertReady($campaign);
        $this->transition($campaign, CampaignStatus::Sending);

        $campaign->forceFill(['started_at' => $campaign->started_at ?? now()])->save();

        return $campaign->fresh();
    }

    public function pause(Campaign $campaign): Campaign
    {
        $this->transition($campaign, CampaignStatus::Paused);
        $campaign->save();

        return $campaign->fresh();
    }

    /**
     * FOLYTATÁS. Ha nincs már kinek küldeni, a kampány BEFEJEZETT — nem `sending`.
     *
     * Enélkül egy szüneteltetett, majd folytatott kampány örökre `sending` maradna, és a
     * felületen „küldés folyamatban"-t mutatna anélkül, hogy bármi történne.
     */
    public function resume(Campaign $campaign): Campaign
    {
        if ($campaign->recipients()->where('status', 'pending')->doesntExist()) {
            return $this->complete($campaign);
        }

        $this->transition($campaign, CampaignStatus::Sending);
        $campaign->save();

        return $campaign->fresh();
    }

    public function complete(Campaign $campaign): Campaign
    {
        $this->transition($campaign, CampaignStatus::Sent);

        $campaign->forceFill(['finished_at' => now()])->save();

        return $campaign->fresh();
    }

    public function cancel(Campaign $campaign): Campaign
    {
        $this->transition($campaign, CampaignStatus::Canceled);

        $campaign->forceFill(['finished_at' => now()])->save();

        // A MÉG NEM KIKÜLDÖTT címzettek `skipped` állapotba kerülnek, nem törlődnek: a
        // riportnak meg kell tudnia mondani, hogy hányan NEM kapták meg, és miért.
        $campaign->recipients()->where('status', 'pending')->update([
            'status' => 'skipped',
            'skip_reason' => 'campaign_canceled',
            'updated_at' => now(),
        ]);

        return $campaign->fresh();
    }

    /**
     * Az ÁTMENET ellenőrzése és végrehajtása.
     *
     * A tiltott átmenet KIVÉTEL, nem csendes no-op: egy „nem történt semmi" válasz után a
     * hívó azt hinné, hogy sikerült, és a kampány örökre a régi állapotban maradna.
     */
    private function transition(Campaign $campaign, CampaignStatus $target): void
    {
        $current = $campaign->statusEnum();

        if ($current === $target) {
            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'Érvénytelen kampány-átmenet: %s → %s. Engedélyezett innen: %s.',
                $current->value,
                $target->value,
                implode(', ', CampaignStatus::transitions()[$current->value] ?: ['(nincs — végállapot)']),
            ));
        }

        $campaign->status = $target->value;
    }

    /**
     * KÉSZ-e a kampány küldésre?
     *
     * Ez a lista szándékosan ITT van, nem a hívóknál: az „elfelejtett feltétel" a
     * legdrágább hiba egy kampánynál — egy tárgy nélküli levél a teljes közönségnek megy ki.
     */
    private function assertReady(Campaign $campaign): void
    {
        $missing = [];

        if ($campaign->template_id === null) {
            $missing[] = 'sablon';
        }

        if (trim((string) $campaign->subject) === '') {
            $missing[] = 'tárgy';
        }

        if (trim((string) $campaign->from_email) === '') {
            $missing[] = 'feladó';
        }

        if ((array) $campaign->target_lists === [] && (array) $campaign->target_segments === []) {
            $missing[] = 'célzás (lista vagy szegmens)';
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'A kampány még nem indítható, hiányzik: '.implode(', ', $missing).'.'
            );
        }

        // A VARIÁNSOK SÚLYA 100% — a maradékot NEM osztjuk szét csendben.
        //
        // Egy 60/30-as beállításnál a hiányzó 10% vagy elveszne (tíz százalék a közönség
        // nem kap levelet), vagy valahova kerülne — és a felhasználó egyik esetben sem
        // tudná, melyik történt.
        if ($campaign->hasVariants()) {
            $total = (int) $campaign->variants()->sum('weight');

            if ($total !== 100) {
                throw new InvalidArgumentException(sprintf(
                    'Az A/B variánsok súlyának összege 100 kell legyen (most %d).',
                    $total
                ));
            }
        }

        // A CÉLZÁS ne legyen ÜRES. A becslés friss: a küldés előtti pillanatban kérdezzük.
        if ($this->audience->estimate($campaign) === 0) {
            throw new InvalidArgumentException(
                'A célzás egyetlen elérhető címzettet sem ad. Ellenőrizd a listákat, a '
                .'szegmenseket és a kizárásokat.'
            );
        }
    }
}
