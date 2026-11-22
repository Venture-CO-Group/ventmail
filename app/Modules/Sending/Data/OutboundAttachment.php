<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/**
 * Egy kimenő csatolmány, ELLENŐRZÖTT állapotban (H123).
 *
 * A példány akkor jön létre, amikor a bemenet már átment a típus-allowlisten, a
 * tartalom-szippantáson, a méretkorlátokon és a fájlnév-tisztításon — a driver és a
 * MIME-építő tehát már nem ellenőriz, csak csatol.
 *
 * A `content` a NYERS bájtsor. Szándékosan nincs `__toString`/`jsonSerialize`: egy
 * véletlen naplózás vagy szerializálás ne vigye ki a fotót a logba.
 */
final readonly class OutboundAttachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    /** @return array{filename: string, content_type: string, size: int} */
    public function meta(): array
    {
        return ['filename' => $this->filename, 'content_type' => $this->contentType, 'size' => $this->size()];
    }

    /** A naplózáshoz és a var_dump-hoz: a bájtok helyett csak a metaadat. */
    public function __debugInfo(): array
    {
        return $this->meta();
    }
}
