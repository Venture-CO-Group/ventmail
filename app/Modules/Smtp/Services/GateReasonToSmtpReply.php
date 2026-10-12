<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Services;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Smtp\Protocol\SmtpReply;

/**
 * A gate strukturált oka → SMTP-válaszkód (ADR 0001).
 *
 * A leképezés ÜZENET a legacy kliensnek: ami PERMANENS, arra `5xx` (ne próbálja újra —
 * különben napokig bombázza a saját sorát), ami ÁTMENETI, arra `4xx` (próbálja újra —
 * különben egy kvóta-csúcs miatt véglegesen elveszne a levél).
 */
final class GateReasonToSmtpReply
{
    public static function map(string $reason, string $uuid): SmtpReply
    {
        return match ($reason) {
            GateReason::DomainUnverified->value => new SmtpReply(550, 'Sender domain not verified', '5.7.1'),
            GateReason::Suppressed->value => new SmtpReply(550, 'Recipient suppressed', '5.7.1'),
            GateReason::NoConsent->value => new SmtpReply(550, 'No confirmed consent for marketing', '5.7.1'),
            GateReason::ValidationRisk->value => new SmtpReply(550, 'Recipient address rejected', '5.1.1'),
            GateReason::InvalidRequest->value => new SmtpReply(550, 'Message rejected', '5.6.0'),
            GateReason::SendingRestricted->value => new SmtpReply(550, 'Recipient not allowed in seed mode', '5.7.1'),
            GateReason::ChannelDisabled->value => new SmtpReply(550, 'Marketing sending disabled', '5.7.1'),
            GateReason::QuotaExceeded->value => new SmtpReply(452, 'Quota exceeded, try again later', '4.3.1'),
            GateReason::RateLimited->value => new SmtpReply(452, 'Rate limited, try again later', '4.3.2'),
            // Fail-closed: inkább KÉSÉS, mint ellenőrizetlen küldés.
            default => new SmtpReply(451, 'Temporary failure ('.$uuid.')', '4.3.0'),
        };
    }
}
