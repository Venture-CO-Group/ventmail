<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Data;

/** Egy csatolmány a levélből — a TARTALOM még memóriában. */
final readonly class ParsedAttachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $contents,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }
}
