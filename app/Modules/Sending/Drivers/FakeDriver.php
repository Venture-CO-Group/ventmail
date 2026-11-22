<?php

declare(strict_types=1);

namespace App\Modules\Sending\Drivers;

use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;

/**
 * Determinisztikus driver a fejlesztéshez és a teszthez.
 *
 * Ez teszi lehetővé, hogy a TELJES küldési lánc (gate → sor → esemény → napló → analitika)
 * kész legyen, mielőtt a Postal cluster egyáltalán létezik. Élesedéskor CSAK a config vált.
 *
 * A KIMENT levelet RÖGZÍTI (címzettenként a személyre szabott törzzsel): enélkül a teszt
 * csak azt tudná ellenőrizni, hogy hívtuk a drivert — azt nem, hogy MI ment ki. A rögzítés
 * kizárólag memóriában él, és a `reset()` üríti.
 */
final class FakeDriver implements SendingDriver
{
    /** @var list<array{uuid: string, channel: string, bodies: array<string, array{html: ?string, text: ?string}>, attachments: list<array{filename: string, content_type: string, size: int, sha256: string}>}> */
    public static array $sent = [];

    public static function reset(): void
    {
        self::$sent = [];
    }

    public function send(Message $message): DispatchResult
    {
        self::$sent[] = [
            'uuid' => (string) $message->uuid,
            'channel' => (string) $message->channel,
            'bodies' => $message->renderedBodies,
            // A csatolmány METAADATA + lenyomata (a bájtok nem kellenek a teszthez, és
            // memóriában sem tartunk fotót tovább a szükségesnél).
            'attachments' => array_map(fn ($attachment) => [
                ...$attachment->meta(),
                'sha256' => hash('sha256', $attachment->content),
            ], $message->outboundAttachments()),
        ];

        // Determinisztikus azonosító: ugyanaz az üzenet ugyanazt az id-t kapja, tehát az
        // idempotencia a driver szintjén is ellenőrizhető.
        // A fake az MTA VISSZAJELZÉSÉT is szimulálja: a valódi driverek csak `accepted`-et
        // adnak, és a `delivered` a webhookból érkezik (A3).
        return DispatchResult::delivered('fake-'.$message->uuid);
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            name: 'fake',
            supportsRawMime: true,
            supportsIpPools: true,
            supportsScheduling: true,
            supportsTracking: true,
        );
    }

    public function health(): DriverHealth
    {
        return new DriverHealth(healthy: true, detail: 'A fake driver mindig elérhető.');
    }
}
