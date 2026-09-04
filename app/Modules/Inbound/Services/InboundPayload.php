<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;

/**
 * A `forward(url)` JSON payloadja (31., 38. kritérium; spec 10.3).
 *
 * Mailgun-paritás: feladó, címzett, tárgy, `body-plain`, `body-html`, `stripped-text`,
 * fejlécek, csatolmány-lista PRESIGNED URL-lel, spam-pontszám, SPF/DKIM eredmény.
 *
 * A csatolmány URL-je LEJÁRÓ, és CSAK a tisztának bizonyultra adunk ilyet. A fertőzött
 * csatolmány a payloadban szerepel — az ügyfélnek látnia kell, mi érkezett —, de letölthető
 * link nélkül: nem a mi dolgunk kártevőt terjeszteni, még kérésre sem.
 */
final class InboundPayload
{
    public function __construct(private readonly RawMailStore $store) {}

    /** @return array<string, mixed> */
    public function for(InboundMessage $message): array
    {
        return [
            'id' => $message->uuid,
            'timestamp' => $message->received_at?->getTimestamp(),
            'sender' => $message->sender,
            'recipient' => $message->recipient,
            'subject' => $message->subject,
            'message-id' => $message->message_id,
            'body-plain' => $message->body_plain,
            'body-html' => $message->body_html,
            // A REPLY-KEZELÉS mezője: az idézett előzmény és az aláírás nélkül.
            'stripped-text' => $message->stripped_text,
            'headers' => $message->headers,
            'authentication' => [
                'spf' => $message->spf_result,
                'dkim' => $message->dkim_result,
                'dmarc' => $message->dmarc_result,
                'spam_score' => $message->spam_score,
            ],
            'attachments' => $message->attachments
                ->map(fn (InboundAttachment $attachment) => [
                    'name' => $attachment->filename,
                    'content_type' => $attachment->content_type,
                    'size' => $attachment->size,
                    'sha256' => $attachment->sha256,
                    'scan' => $attachment->scan_result,
                    // CSAK a tisztára. A `null` itt üzenet is: „megérkezett, de nem adjuk ki".
                    'url' => $attachment->downloadable()
                        ? $this->store->temporaryUrl(
                            (string) $attachment->storage_key,
                            (int) config('inbound.download_url_seconds'),
                        )
                        : null,
                ])->values()->all(),
        ];
    }
}
