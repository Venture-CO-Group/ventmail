<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Testing;

use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Data\SubmissionPrincipal;

/** Determinisztikus hitelesítő a protokoll-tesztekhez — a kontraktus tesztelt párja. */
final class FakeSmtpAuthenticator implements SmtpAuthenticator
{
    /** @param array<string, string> $credentials felhasználónév => jelszó */
    public function __construct(
        private readonly array $credentials = [],
        private readonly int $workspaceId = 1,
    ) {}

    public function authenticate(string $username, string $password, ChannelSecurity $security): ?SubmissionPrincipal
    {
        // A fake sem hitelesít titkosítatlan csatornán: különben egy teszt véletlenül
        // olyan viselkedést rögzítene, amit élesben tiltunk.
        if (! $security->encrypted) {
            return null;
        }

        $expected = $this->credentials[$username] ?? null;

        if ($expected === null || ! hash_equals($expected, $password)) {
            return null;
        }

        return new SubmissionPrincipal(
            workspaceId: $this->workspaceId,
            credentialUuid: 'fake-'.$username,
        );
    }
}
