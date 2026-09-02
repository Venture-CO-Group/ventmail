<?php

declare(strict_types=1);

namespace App\Modules\Sending\Drivers;

use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Naplózó driver: nem küld, csak rögzít. Lokális fejlesztéshez, ahol a determinisztikus
 * esemény-visszajátszás sem kell.
 *
 * A napló SOHA nem tartalmaz tartalmat vagy címzettet — csak azonosítót és metaadatot.
 */
final class LogDriver implements SendingDriver
{
    public function send(Message $message): DispatchResult
    {
        Log::info('ventMail kimenő üzenet (log driver)', [
            'message_uuid' => $message->uuid,
            'workspace_id' => $message->workspace_id,
            'channel' => $message->channel,
            'recipient_count' => count($message->recipients),
        ]);

        return DispatchResult::delivered('log-'.$message->uuid);
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(name: 'log');
    }

    public function health(): DriverHealth
    {
        return new DriverHealth(healthy: true, detail: 'A log driver mindig elérhető.');
    }
}
