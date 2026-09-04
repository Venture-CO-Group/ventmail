<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Contracts;

use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Protocol\SessionState;
use App\Modules\Smtp\Protocol\SmtpReply;

/**
 * Amit a TRANSZPORT lát egy protokoll-állapotgépből.
 *
 * A transzport (socket, TLS, időkorlát, per-IP keret) mindkét oldalon UGYANAZ — a
 * submissionön és az MX-en is. Ami különbözik, az a PROTOKOLL-SZABÁLY, és épp azt nem
 * szabad összemosni: a két oldal invariánsa más (ADR 0002).
 *
 * Ez az interfész a határ. A transzport nem tud principalról, feloldott címzettről, se
 * hitelesítésről — csak arról, hogy sorokat ad be és válaszokat kap.
 */
interface SmtpConnection
{
    public function greeting(): SmtpReply;

    /** @return list<SmtpReply> pipelining miatt egy sor több választ is adhat */
    public function feed(string $line): array;

    public function state(): SessionState;

    /** Mennyi levéltörzset tart épp a memóriában (globális plafonhoz). */
    public function bufferedBytes(): int;

    public function shouldClose(): bool;

    public function shouldStartTls(): bool;

    /** A TLS-váltás UTÁN a session MINDENT elfelejt (STARTTLS command-injection). */
    public function securedWith(ChannelSecurity $security): void;
}
