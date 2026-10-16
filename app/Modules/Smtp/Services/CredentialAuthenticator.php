<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Services;

use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Data\SubmissionPrincipal;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\VentSec\Contracts\VentSecEvents;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Az `smtp_credentials` tábla mögötti hitelesítő.
 *
 * Két dolog nem alku:
 * 1. TITKOSÍTATLAN CSATORNÁN NINCS HITELESÍTÉS. Az állapotgép is tiltja, de a védelem itt
 *    is megvan: aki egy jövőbeli transzportot ír, ne tudja véletlenül kinyitni.
 * 2. NINCS FELHASZNÁLÓ-FELDERÍTŐ ORÁKULUM. Ismeretlen felhasználónévnél is lefut egy
 *    dummy jelszó-ellenőrzés, különben a válaszidőből kiderülne, melyik név létezik.
 */
final class CredentialAuthenticator implements SmtpAuthenticator
{
    /**
     * A dummy hash a KONFIGURÁLT költséggel készül, futásidőben.
     *
     * Egy beégetett cost=12-es hash mellett a valódi (PHP 8.3-on cost=10) ellenőrzés
     * GYORSABB lenne — mérve ~59 ms vs ~235 ms —, tehát a válaszidőből kiderülne, melyik
     * felhasználónév létezik. A dummy csak akkor ér valamit, ha ugyanannyi munkát végez.
     */
    private static ?string $dummyHash = null;

    public function __construct(private readonly AuthThrottle $throttle, private readonly ?VentSecEvents $events = null) {}

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('ventmail-dummy-'.bin2hex(random_bytes(8)));
    }

    public function authenticate(string $username, string $password, ChannelSecurity $security): ?SubmissionPrincipal
    {
        if (! $security->encrypted) {
            return $this->failed('plaintext_channel');
        }

        $credential = SmtpCredential::findByUsername($username);

        if ($credential === null) {
            // Időben azonos út: enélkül a nem létező név ELŐBB adna választ.
            Hash::check($password, $this->dummyHash());

            return $this->failed('invalid_credentials');
        }

        if (! Hash::check($password, (string) $credential->password_hash)) {
            return $this->failed('invalid_credentials');
        }

        // A lejárt/visszavont hitelesítő NEM hitelesít — de a hiba ugyanaz kifelé.
        if (! $credential->isUsable()) {
            return $this->failed('credential_unusable');
        }

        return new SubmissionPrincipal(
            workspaceId: (int) $credential->workspace_id,
            credentialUuid: (string) $credential->uuid,
            domainId: $credential->domain_id === null ? null : (int) $credential->domain_id,
            channel: (string) ($credential->channel_restriction ?? 'transactional'),
        );
    }

    /**
     * `smtp.auth_failed` a ventSec-nek (VSES): CSAK a listener és egy zárt okkód — felhasználónév,
     * jelszó, IP soha. A kibocsátás hibája nem érinti a hitelesítést (a válasz mindig `null`).
     */
    private function failed(string $reason): ?SubmissionPrincipal
    {
        try {
            ($this->events ?? app(VentSecEvents::class))->emit(
                'smtp.auth_failed',
                'failure',
                ['listener' => 'submission', 'reason' => $reason],
            );
        } catch (Throwable $e) {
            Log::warning('A ventSec-esemény kibocsátása nem sikerült.', ['action' => 'smtp.auth_failed', 'error' => $e::class]);
        }

        return null;
    }
}
