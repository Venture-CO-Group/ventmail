<?php

declare(strict_types=1);

namespace App\Modules\Messages\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Messages\Models\RawMimeMessage;
use App\Modules\Messages\Services\MimeParser;
use App\Modules\Sending\Actions\SendMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * `POST /v1/messages.mime` — nyers MIME továbbítása (spec 8.1).
 *
 * A törzs VÁLTOZATLANUL megy tovább; a fejlécekből csak annyit olvasunk ki, amennyi a
 * PreSendGate döntéséhez kell. A gate itt is megkerülhetetlen (golden rule 2).
 */
final class SendMimeController
{
    public function __invoke(Request $request, MimeParser $parser, SendMessage $send): JsonResponse
    {
        $file = $request->file('message');

        if ($file === null) {
            return ApiError::badRequest(
                'MISSING_MIME',
                'A `message` mezőben a nyers MIME-levelet kell feltölteni (multipart/form-data).'
            );
        }

        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        if (! $file->isValid()) {
            return ApiError::badRequest('MISSING_MIME', 'A feltöltött MIME-fájl sérült.');
        }

        $maxBytes = (int) config('sending.max_mime_bytes');

        // A méretet a BEOLVASÁS ELŐTT ellenőrizzük: különben a teljes tartalom a memóriába
        // kerül, és a PHP `post_max_size` az egyetlen fék — az pedig nem strukturált hiba.
        if ($file->getSize() > $maxBytes) {
            return ApiError::badRequest('MIME_TOO_LARGE', sprintf(
                'A MIME-levél mérete %d bájt, a felső korlát %d bájt.',
                (int) $file->getSize(),
                $maxBytes
            ));
        }

        // A MIME-út ma nem értelmezi az `o:` vezérlőket (a levél kész, a fejlécei a
        // feladóé). A CSENDES eldobás rosszabb szerződés: aki `o:testmode=yes`-szel
        // migrál, valódi levelet küldene.
        $unsupported = collect($request->all())
            ->keys()
            ->filter(fn (string $key) => str_starts_with($key, 'o:') && $key !== 'o:channel')
            ->values();

        if ($unsupported->isNotEmpty()) {
            return ApiError::badRequest('UNSUPPORTED_MIME_OPTION', sprintf(
                'A MIME-úton nem értelmezett vezérlő(k): %s. A levél fejlécei a feladóé; '
                .'ezeket a strukturált végponton add meg.',
                $unsupported->implode(', ')
            ));
        }

        $raw = (string) file_get_contents($file->getRealPath());

        try {
            $parsed = $parser->parseHeaders($raw);
        } catch (InvalidArgumentException $e) {
            [$code, $message] = array_pad(explode('|', $e->getMessage(), 2), 2, '');

            return ApiError::badRequest($code, $message);
        }

        // A `to` felülírhatja a MIME `To:` fejlécét (Mailgun-viselkedés): a boríték-címzett
        // és a fejléc-címzett nem ugyanaz.
        $recipients = $request->has('to')
            ? array_map('trim', explode(',', (string) $request->input('to')))
            : $parsed['recipients'];

        $result = $send([
            'channel' => (string) $request->input('o:channel', 'transactional'),
            'from_email' => $parsed['from_email'],
            'from_name' => $parsed['from_name'],
            'subject' => $parsed['subject'],
            'to' => $recipients,
            'headers' => $parsed['headers'],
            'options' => ['raw_mime' => true, 'mime_size' => strlen($raw)],
            'testmode' => $apiKey?->testmode === true || $request->boolean('o:testmode'),
            'idempotency_key' => (string) $request->attributes->get('idempotency_key'),
        ]);

        // A nyers MIME MAGA a levél: megőrizzük, különben nincs mit kiküldeni és nincs
        // mit trace-elni.
        if ($result->decision->allowed) {
            RawMimeMessage::create(['message_uuid' => $result->message->uuid, 'raw' => $raw]);
        }

        if (! $result->decision->allowed) {
            return ApiError::rejected(
                (string) $result->decision->reason,
                'A nyers MIME küldést a PreSendGate elutasította.',
                ['id' => $result->message->uuid]
            );
        }

        return response()->json([
            'id' => $result->message->uuid,
            'message' => 'Queued. Thank you.',
            'status' => $result->message->status,
        ], 202);
    }
}
