<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Events\Models\MessageEvent;
use InvalidArgumentException;

/**
 * A/B GYŐZTES kihirdetése (64. kritérium).
 *
 * ## A KRITÉRIUM KIMONDVA, nem „a jobbik nyer"
 *
 * A győztes-kritérium `open_rate` vagy `click_rate`, és a választás nem mindegy: a nyitás
 * mérése az Apple Mail Privacy Protection óta MEGBÍZHATATLAN (a proxy MINDEN levelet
 * megnyit, tehát a nyitási arány felfelé torzul és a variánsok között elmosódik). A klikk
 * pontosabb, de ritkább — kis közönségnél nincs elég adat.
 *
 * Ezért a kritérium a HÍVÓ döntése, és a döntés RÖGZÜL (`decided_by`): egy fél évvel későbbi
 * kérdésre („miért ez lett a győztes?") a riportnak válaszolnia kell.
 *
 * ## A MINIMÁLIS MINTA nem opcionális
 *
 * Tíz címzettnél a 60%-os nyitási arány hat embert jelent — a különbség mintavételi zaj. A
 * kihirdetés ezért küszöb alatt ELUTASÍTÁS, nem egy halkan megjelölt győztes: egy hamis
 * eredmény alapján a tenant a következő tíz kampányát rossz irányba optimalizálja.
 *
 * ## AZ EGYENLŐSÉG nem győzelem
 *
 * Ha a két variáns rátája megegyezik, NINCS győztes. A „döntetlennél az elsőt választjuk"
 * szabály azt jelentené, hogy a variánsok ábécésorrendje dönt — és a tenant azt hinné, hogy
 * a tárgysora jobb volt.
 */
final class VariantWinner
{
    /** A kihirdetéshez szükséges minimális küldésszám variánsonként. */
    private const MIN_SAMPLE = 100;

    /**
     * A SZÁMLÁLÓK FRISSÍTÉSE az esemény-forrásból.
     *
     * Ugyanabból a `message_events` táblából, amiből a platform-napló és a marketing
     * analitika (B4) dolgozik — ez a 73. kritérium konzisztencia-követelményének alapja: a
     * kampány ugyanazt a számot adja mindkét felületen, mert ugyanaz a forrás.
     */
    public function refresh(Campaign $campaign): void
    {
        foreach ($campaign->variants as $variant) {
            $tag = 'variant:'.$variant->label;

            $variant->forceFill([
                // AZ EGYEDI címzett számít, nem az esemény: aki háromszor nyitja meg a
                // levelet, egy nyitás. Enélkül egy kíváncsi címzett egymaga „megnyerhetne"
                // egy variánst.
                'opened_count' => $this->uniqueRecipients($campaign, $tag, 'opened'),
                'clicked_count' => $this->uniqueRecipients($campaign, $tag, 'clicked'),
            ])->save();
        }
    }

    /**
     * A GYŐZTES kihirdetése.
     *
     * @param  'open_rate'|'click_rate'  $criterion
     */
    public function declare(Campaign $campaign, string $criterion = 'open_rate'): ?CampaignVariant
    {
        if (! in_array($criterion, ['open_rate', 'click_rate'], true)) {
            throw new InvalidArgumentException("Ismeretlen győztes-kritérium: {$criterion}.");
        }

        $this->refresh($campaign);

        $variants = $campaign->variants()->orderBy('id')->get();

        if ($variants->count() < 2) {
            return null;
        }

        // A MINTA MINDEN variánsnál elég legyen: ha az egyik alulmintázott, a másikkal való
        // összehasonlítás értelmetlen.
        foreach ($variants as $variant) {
            if ((int) $variant->sent_count < self::MIN_SAMPLE) {
                throw new InvalidArgumentException(sprintf(
                    'A(z) „%s" variáns még csak %d levelet küldött; a kihirdetéshez legalább %d kell '
                    .'variánsonként. Kisebb mintán a különbség mintavételi zaj.',
                    $variant->label,
                    (int) $variant->sent_count,
                    self::MIN_SAMPLE,
                ));
            }
        }

        $rates = $variants->mapWithKeys(fn (CampaignVariant $variant) => [
            (int) $variant->getKey() => $criterion === 'open_rate' ? $variant->openRate() : $variant->clickRate(),
        ]);

        $best = $rates->max();

        // DÖNTETLEN: nincs győztes. Az ábécésorrend nem eredmény.
        if ($rates->filter(fn (float $rate) => $rate === $best)->count() > 1) {
            return null;
        }

        $winner = $variants->firstWhere('id', $rates->search($best));

        foreach ($variants as $variant) {
            $variant->forceFill([
                'is_winner' => $variant->is($winner),
                'declared_at' => $variant->is($winner) ? now() : null,
                'decided_by' => $variant->is($winner) ? $criterion : null,
            ])->save();
        }

        return $winner?->fresh();
    }

    /**
     * EGYEDI címzettek száma egy esemény-típusra.
     *
     * A `workspace_id` a global scope-ból jön (a `MessageEvent` tenant-kötött modell).
     */
    private function uniqueRecipients(Campaign $campaign, string $tag, string $type): int
    {
        return MessageEvent::query()
            ->where('type', $type)
            ->whereJsonContains('tags', 'campaign:'.$campaign->uuid)
            ->whereJsonContains('tags', $tag)
            ->distinct()
            ->count('recipient');
    }
}
