<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Sending\Models\Message;
use Symfony\Component\Mime\Email;

/**
 * A kimenő levél MIME-ja — címzettenként, a személyre szabott törzzsel.
 *
 * Miért kell nekünk összeállítani, ha az MTA is tudná: mert a DKIM-et MI írjuk alá (21.
 * kritérium), és aláírni csak azt lehet, ami már összeállt. Ha az MTA-ra bíznánk, a
 * kulcsaink is nála lennének — és a rotációt (M0-4) sem tudnánk vezérelni.
 */
final class OutboundMimeBuilder
{
    public function build(Message $message, string $recipient): Email
    {
        return $this->assemble(
            $message,
            $recipient,
            $message->renderedBodies[$recipient] ?? ['html' => null, 'text' => null]
        );
    }

    /** @param array{html: ?string, text: ?string} $bodies */
    private function assemble(Message $message, string $recipient, array $bodies): Email
    {
        $email = new Email;

        $email->from($this->from($message))
            ->to($recipient)
            ->subject((string) $message->subject);

        if (($bodies['text'] ?? null) !== null) {
            $email->text((string) $bodies['text']);
        }

        if (($bodies['html'] ?? null) !== null) {
            $email->html((string) $bodies['html']);
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $message->uuid.'@'.$this->domainOf($message));
        $headers->addDateHeader('Date', now()->toDateTimeImmutable());

        // A feladó `h:X-*` fejlécei — a vezérlőket a kapu már leválasztotta.
        foreach ((array) $message->headers as $name => $value) {
            if (is_scalar($value) && str_starts_with(strtolower((string) $name), 'x-')) {
                $headers->addTextHeader((string) $name, (string) $value);
            }
        }

        return $email;
    }

    private function from(Message $message): string
    {
        return $message->from_name === null
            ? (string) $message->from_email
            : sprintf('%s <%s>', $message->from_name, $message->from_email);
    }

    private function domainOf(Message $message): string
    {
        return (string) substr(strrchr((string) $message->from_email, '@') ?: '@localhost', 1);
    }
}
