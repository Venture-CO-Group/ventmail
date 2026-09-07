<?php

declare(strict_types=1);

namespace App\Modules\Validation\Contracts;

use App\Modules\Validation\Data\ProbeResult;

/**
 * MAILBOX-SZONDA: létezik-e ez a postafiók? (40. kritérium)
 *
 * SMTP-párbeszéd a fogadó MX-szel `RCPT TO`-ig, DATA nélkül. Interfész mögött, mert
 * (a) a teszt determinisztikus kell legyen valódi hálózat nélkül, és (b) ez a
 * legérzékenyebb művelet a modulban: minden szonda egy IDEGEN szerverhez intézett kérés a
 * MI IP-nkről.
 *
 * Ezért a hívó SOSEM közvetlenül hívja: a `RateLimitedProbe` áll előtte cache-sel és
 * kerettel. Aki száz címet ellenőriz ugyanazon a domainen, az nem küldhet száz szondát —
 * a fogadó ezt (joggal) címfelderítésnek veszi, és blokkolja az IP-nket.
 */
interface MailboxProbe
{
    public function probe(string $email): ProbeResult;
}
