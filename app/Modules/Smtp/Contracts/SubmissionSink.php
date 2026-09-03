<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Contracts;

use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Data\SubmissionPrincipal;
use App\Modules\Smtp\Protocol\SmtpReply;

/**
 * A modul EGYETLEN kijárata a küldés felé.
 *
 * Az első paraméter nem-nullable `SubmissionPrincipal`: hitelesítetlen sessionből a hívás
 * NEM MEGFOGALMAZHATÓ. A nyílt relay tilalma így típus-szinten él, nem egy `if`-en
 * (ADR 0001, D3 — és a 12. kritérium reflection-tesztje pontosan ezt méri).
 */
interface SubmissionSink
{
    public function accept(SubmissionPrincipal $principal, SmtpEnvelope $envelope, string $rawMime): SmtpReply;
}
