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
        /**
         * A KÓDOLT méret, ha a tartalmat el sem dekódoltuk.
         *
         * A plafon fölötti csatolmányt nem dekódoljuk (a memória miatt), de a méretét
         * ismerjük — és a hívónak épp arra van szüksége, hogy elutasíthassa.
         */
        public ?int $declaredSize = null,
    ) {}

    public function size(): int
    {
        return $this->declaredSize ?? strlen($this->contents);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }
}
