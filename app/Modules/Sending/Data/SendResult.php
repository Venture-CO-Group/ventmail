<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

use App\Modules\Sending\Gate\GateDecision;
use App\Modules\Sending\Models\Message;

/** A teljes küldési kísérlet eredménye: a gate döntése ÉS a keletkezett üzenet. */
final readonly class SendResult
{
    public function __construct(
        public Message $message,
        public GateDecision $decision,
    ) {}
}
