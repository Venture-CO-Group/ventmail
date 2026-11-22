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
        // A törzset FOGYASZTJA (különben a személyre szabás észrevétlenül elveszne), de
        // csak a LENYOMATÁT naplózza: a kirenderelt levél magic-linket és tokent hordoz.
        $fingerprints = [];

        foreach ($message->renderedBodies as $recipient => $bodies) {
            $fingerprints[] = [
                'recipient_sha256' => hash('sha256', (string) $recipient),
                'html_sha256' => $bodies['html'] === null ? null : hash('sha256', $bodies['html']),
                'text_sha256' => ($bodies['text'] ?? null) === null ? null : hash('sha256', (string) $bodies['text']),
            ];
        }

        Log::info('ventMail kimenő üzenet (log driver)', [
            'message_uuid' => $message->uuid,
            'workspace_id' => $message->workspace_id,
            'channel' => $message->channel,
            'recipient_count' => count($message->recipients),
            // Csatolmány: csak darabszám és méret — sem tartalom, sem fájlnév.
            'attachments' => array_map(
                fn ($attachment) => ['content_type' => $attachment->contentType, 'size' => $attachment->size()],
                $message->outboundAttachments()
            ),
            'bodies' => $fingerprints,
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
