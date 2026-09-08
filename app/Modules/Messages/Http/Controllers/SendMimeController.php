<?php

declare(strict_types=1);

namespace App\Modules\Messages\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Messages\Services\MimeParser;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
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
            // Külön kód: a „nem küldtél levelet" és a „sérülten érkezett meg" két
            // különböző hívói hiba, és a kliens másképp is kezeli őket.
            return ApiError::badRequest('INVALID_MIME_UPLOAD', 'A feltöltött MIME-fájl sérült.');
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
        $to = $request->input('to');

        // Tömb-bemenet (`to[]=a&to[]=b`) itt is strukturált hiba, nem 500: ugyanaz a
        // hibaosztály, mint a `subject`/`o:channel` mezőn.
        if ($to !== null && ! is_scalar($to)) {
            return ApiError::badRequest(
                'VALIDATION_ERROR',
                'A `to` mező vesszővel elválasztott listát vár, nem tömböt.'
            );
        }

        $recipients = $to !== null
            ? array_map('trim', explode(',', (string) $to))
            : $parsed['recipients'];

        $channel = $request->input('o:channel', 'transactional');

        /*
         | A NYERS MIME ÚT ÉS A LEIRATKOZÓ FEJLÉC.
         |
         | A nyers MIME EGY bájtsor, amit MINDEN címzett ugyanúgy kap meg (`sendRaw` egyetlen
         | blobot ad át `rcpt_to` listával). Ebből két dolog következik, és mindkettőt itt
         | kell lezárni, mert a `MimeParser` csak az `X-*` fejléceket adja tovább — a
         | `List-Unsubscribe` a modell és így a KAPUK elől is eltűnik:
         |
         |  1. Az ügyfél `List-Unsubscribe` fejléce ellenőrizetlenül, a MI DKIM-aláírásunkkal
         |     ment volna ki. Egy idegen tenant elkapott tokenjével minden one-click egy
         |     IDEGEN workspace visszavonhatatlan suppression-listájára írt (mérve) — és itt
         |     még százalék-kódolás sem kellett hozzá, mert a kapu ezt a fejlécet nem is látta.
         |  2. Consent-köteles csatornán CÍMZETTENKÉNTI token kell (RFC 8058). Egy közös
         |     blobba csak egyetlen token férne, tehát Béla leiratkozása Annát vinné a listára.
         |     Ezért a marketing/kampány/journey nyers MIME EGY címzettre engedett — ott a
         |     „címzettenkénti" és a „közös" token ugyanaz.
         |
         | A második pont Mailgun-paritás-szűkítés, és tudatos: a tömeges marketing útja a
         | strukturált végpont vagy a tárolt sablon, ahol a MIME-ot mi építjük címzettenként.
         */
        if ($parsed['unsubscribe_headers'] !== []) {
            return ApiError::badRequest('UNSUPPORTED_MIME_HEADER', sprintf(
                'A(z) %s fejléc(ek) a nyers MIME-ban nem engedettek: a leiratkozó fejlécet a '
                .'ventMail kezeli (RFC 8058 one-click), és a nyers levélben nem tudjuk '
                .'címzettenként átírni. A saját preference centeredre mutató linket a levél '
                .'törzsébe tedd.',
                implode(', ', $parsed['unsubscribe_headers'])
            ));
        }

        if (Channel::tryFrom(is_scalar($channel) ? (string) $channel : '')?->requiresConsent() === true
            && count($recipients) > 1) {
            return ApiError::badRequest('MIME_BULK_MARKETING', sprintf(
                'Consent-köteles csatornán (%s) a nyers MIME EGY címzettre küldhető: a '
                .'one-click leiratkozó token a címzett SZEMÉLYÉT hordozza, egy közös '
                .'bájtsorba viszont csak egy token férne — a második címzett leiratkozása az '
                .'elsőt vinné a suppression-listára. Tömeges küldéshez a strukturált '
                .'végpontot vagy a tárolt sablont használd.',
                (string) $channel
            ));
        }

        $result = $send([
            // A tömb-bemenet `(string)` castja korábban 500-at adott az egységes
            // hibaformátum helyett; a nem skalár érték itt egyszerűen érvénytelen csatorna.
            'channel' => is_scalar($channel) ? (string) $channel : '',
            'from_email' => $parsed['from_email'],
            'from_name' => $parsed['from_name'],
            'subject' => $parsed['subject'],
            'to' => $recipients,
            'headers' => $parsed['headers'],
            'options' => ['raw_mime' => true, 'mime_size' => strlen($raw)],
            // A `testmode` a KULCSBÓL jön: a teszt-kulcs sosem küld élesben, és a hívó nem
            // kapcsolhatja le. (A MIME-úton `o:testmode` amúgy is 400 — lásd feljebb.)
            'testmode' => $apiKey?->testmode === true,
            'idempotency_key' => (string) $request->attributes->get('idempotency_key'),
            // A levél MAGA. A perzisztálás a `SendMessage`-en belül, a gate és a küldés
            // ELŐTT történik — itt már késő lenne: a dispatch szinkron fut.
            'raw_mime' => $raw,
            // A lenyomatba a TARTALOM is beszámít: enélkül ugyanaz az Idempotency-Key két
            // különböző MIME-levéllel replayt adott, és a második soha nem ment ki.
            'raw_sha256' => hash('sha256', $raw),
        ]);

        if (! $result->decision->allowed) {
            // A kulcs újrahasználata a HÍVÓ hibája, nem a gate elutasítása: 409, hogy a
            // kliens tudja, a második levele NEM ment ki.
            if ($result->decision->reason === GateReason::IdempotencyKeyReused->value) {
                return ApiError::response(
                    GateReason::IdempotencyKeyReused->value,
                    'Ezt az Idempotency-Key-t már használtad MÁS levéllel. Új levélhez új kulcs kell.',
                    409,
                    ['id' => $result->message->uuid]
                );
            }

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
