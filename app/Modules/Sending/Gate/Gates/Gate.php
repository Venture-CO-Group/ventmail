<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/** Egy kapu a PreSendGate láncában. */
interface Gate
{
    /** Rövid, stabil név — ezen a néven jelenik meg a trace-ben. */
    public function name(): string;

    public function check(Message $message): GateResult;
}
