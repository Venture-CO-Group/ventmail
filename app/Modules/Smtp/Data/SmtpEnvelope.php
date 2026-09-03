<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Data;

/** Az SMTP-boríték: `MAIL FROM` + `RCPT TO`. A fejléc-`From` ettől KÜLÖN dolog. */
final class SmtpEnvelope
{
    /** @param list<string> $recipients */
    public function __construct(
        public string $sender = '',
        public array $recipients = [],
        public ?int $declaredSize = null,
    ) {}

    public function reset(): void
    {
        $this->sender = '';
        $this->recipients = [];
        $this->declaredSize = null;
    }
}
