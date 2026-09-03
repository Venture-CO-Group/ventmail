<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Protocol;

/** A session állapotai. A `Data`-ba VEZETŐ ÚT hitelesítés nélkül nem létezik (ADR D3). */
enum SessionState: string
{
    case Greeting = 'greeting';
    case Ready = 'ready';
    case Mail = 'mail';
    case Rcpt = 'rcpt';
    case Data = 'data';
    case Closed = 'closed';
}
