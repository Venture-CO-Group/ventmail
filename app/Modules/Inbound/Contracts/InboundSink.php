<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Contracts;

use App\Modules\Inbound\Data\InboundRecipient;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Protocol\SmtpReply;

/**
 * Az MX-oldal EGYETLEN kijárata.
 *
 * Az első paraméter egy nem-üres, FELOLDOTT címzett-lista: olyan levél, aminek nem a mi
 * domainünk a címzettje, itt NEM MEGFOGALMAZHATÓ. Ugyanaz a bizonyítási minta, mint a
 * submission-oldalon a nem-nullable `SubmissionPrincipal` — csak a másik invariánsra.
 *
 * @see SubmissionSink a kimenő oldal párja
 */
interface InboundSink
{
    /**
     * @param  non-empty-list<InboundRecipient>  $recipients  MIND feloldva, mind a miénk
     */
    public function accept(array $recipients, SmtpEnvelope $envelope, string $rawMime): SmtpReply;
}
