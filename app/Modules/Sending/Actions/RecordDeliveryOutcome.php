<?php

declare(strict_types=1);

namespace App\Modules\Sending\Actions;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Data\BounceClass;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\BounceClassifier;
use App\Modules\Suppression\Models\Suppression;

/**
 * A fogadó válaszának feldolgozása (20. kritérium).
 *
 * Ez köti össze a bounce-osztályozót a VALÓSÁGGAL: az osztály nem önmagáért van, hanem
 * azért, hogy a hard bounce AZONNAL suppression-listára kerüljön (minden további küldés a
 * reputációt rombolja), a soft viszont csak N sikertelen próba után — különben egy nyaraló
 * ügyfél telített postafiókja miatt véglegesen elveszítenénk a címzettet.
 *
 * A hívók: a küldési út (szinkron 5xx/4xx) és az A3 webhook-feldolgozó (aszinkron bounce).
 */
final class RecordDeliveryOutcome
{
    /** Hány soft bounce után kerül a cím suppression-listára. */
    private const SOFT_BOUNCE_LIMIT = 5;

    public function __construct(private readonly BounceClassifier $classifier) {}

    public function __invoke(
        Message $message,
        string $recipient,
        int $code,
        string $response,
        ?string $enhanced = null,
    ): BounceClass {
        $class = $this->classifier->classify($code, $response, $enhanced);

        // A FOGADÓ VÁLASZA a trace-be is bekerül: ez az a sor, amit a support megnéz,
        // amikor az ügyfél azt írja, „nem jött meg". A `lines` a valódi SMTP-párbeszédnél
        // telik meg (A2 relay); API-alapú MTA-nál a kód és a szöveg az, amink van.
        SmtpTransaction::create([
            'message_uuid' => $message->uuid,
            'recipient' => $recipient,
            'ip_address_id' => $message->ip_address_id,
            'response_code' => $code,
            'enhanced_code' => $enhanced,
            'response_text' => $response,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        DeliveryAttempt::create([
            'message_uuid' => $message->uuid,
            'recipient' => $recipient,
            'attempt' => (int) $message->attempts,
            'code' => $code,
            'enhanced_code' => $enhanced,
            'response' => $response,
            'classification' => $class->value,
            'ip_address_id' => $message->ip_address_id,
        ]);

        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => $code >= 500 ? 'bounced' : 'deferred',
            'recipient' => $recipient,
            'channel' => $message->channel,
            'reason' => $class->value,
            'tags' => $message->tags,
            // A fogadó szövege NEM megy az eseménybe: az A3 webhook harmadik félnek küldi
            // ki, és a bounce-szöveg gyakran az eredeti tárgyat is tartalmazza.
            'payload' => ['code' => $code, 'enhanced_code' => $enhanced, 'classification' => $class->value],
        ]);

        if ($class->suppressesImmediately()) {
            $this->suppress($recipient, $class);

            return $class;
        }

        // Soft: N sikertelen próba után már nem a fogadó ideiglenes baja, hanem a cím
        // halott. A számlálás a kísérletekből jön, nem a memóriából.
        $softAttempts = DeliveryAttempt::query()
            ->where('recipient', $recipient)
            ->whereIn('classification', [BounceClass::Soft->value, BounceClass::MailboxFull->value])
            ->count();

        if ($softAttempts >= self::SOFT_BOUNCE_LIMIT) {
            $this->suppress($recipient, $class);
        }

        return $class;
    }

    private function suppress(string $recipient, BounceClass $class): void
    {
        Suppression::query()->firstOrCreate(
            ['email' => strtolower($recipient)],
            ['reason' => $class === BounceClass::Hard ? 'hard_bounce' : 'soft_bounce']
        );
    }
}
