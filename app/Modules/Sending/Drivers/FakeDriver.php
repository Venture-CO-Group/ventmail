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
 */
final class FakeDriver implements SendingDriver
{
    public function send(Message $message): DispatchResult
    {
        // Determinisztikus azonosító: ugyanaz az üzenet ugyanazt az id-t kapja, tehát az
        // idempotencia a driver szintjén is ellenőrizhető.
        return DispatchResult::accepted('fake-'.$message->uuid);
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
