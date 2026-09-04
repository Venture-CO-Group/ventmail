<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Data;

/** Egy értelmezett bejövő levél. */
final readonly class ParsedMail
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<ParsedAttachment>  $attachments
     * @param  list<string>  $warnings  amit a parser NEM tudott feldolgozni — a hívó lássa
     */
    public function __construct(
        public array $headers = [],
        public ?string $subject = null,
        public ?string $messageId = null,
        public ?string $bodyPlain = null,
        public ?string $bodyHtml = null,
        public array $attachments = [],
        public array $warnings = [],
    ) {}
}
