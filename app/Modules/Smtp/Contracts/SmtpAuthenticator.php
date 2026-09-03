<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Contracts;

use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Data\SubmissionPrincipal;

/**
 * SMTP-hitelesítés.
 *
 * A `ChannelSecurity` szándékosan paraméter: a hitelesítő maga is ELUTASÍTHATJA a
 * titkosítatlan csatornát — a védelem nem csak az állapotgépen múlik (védelem a
 * mélységben, golden rule 3).
 */
interface SmtpAuthenticator
{
    public function authenticate(string $username, string $password, ChannelSecurity $security): ?SubmissionPrincipal;
}
