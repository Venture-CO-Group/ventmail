<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Protocol;

/** Egy SMTP-válasz: kód + (opcionális) enhanced status + szöveg. */
final readonly class SmtpReply
{
    public function __construct(
        public int $code,
        public string $text,
        public ?string $enhanced = null,
    ) {}

    public function toLine(): string
    {
        return $this->enhanced === null
            ? sprintf('%d %s', $this->code, $this->text)
            : sprintf('%d %s %s', $this->code, $this->enhanced, $this->text);
    }
}
