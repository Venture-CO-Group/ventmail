<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Testing;

use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Data\SubmissionPrincipal;
use App\Modules\Smtp\Protocol\SmtpReply;

/**
 * Rögzítő nyelő a protokoll-tesztekhez.
 *
 * Nem csak azt tudja megmutatni, hogy HÍVTUK-e a küldést, hanem azt is, MIT adtunk át —
 * a nyílt relay tesztje épp azon áll, hogy hitelesítés nélkül itt SEMMI nem jelenik meg.
 */
final class RecordingSink implements SubmissionSink
{
    /** @var list<array{principal: SubmissionPrincipal, envelope: SmtpEnvelope, raw: string}> */
    public array $accepted = [];

    public function __construct(private readonly ?SmtpReply $reply = null) {}

    public function accept(SubmissionPrincipal $principal, SmtpEnvelope $envelope, string $rawMime): SmtpReply
    {
        $this->accepted[] = ['principal' => $principal, 'envelope' => $envelope, 'raw' => $rawMime];

        return $this->reply ?? new SmtpReply(250, 'Ok: queued', '2.0.0');
    }
}
