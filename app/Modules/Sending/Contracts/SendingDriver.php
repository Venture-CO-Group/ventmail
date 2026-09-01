<?php

declare(strict_types=1);

namespace App\Modules\Sending\Contracts;

use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;

/**
 * A kimenő út egyetlen kapuja (spec 6.1, LOCKED).
 *
 * A domain-logika sosem ismeri a Postal (vagy bármely MTA) belsőségeit: a driver-csere
 * KIZÁRÓLAG config (`SENDING_DRIVER`), nem kódmódosítás. Aki ezt hívja, az már átment a
 * PreSendGate hat kapuján — a hívás helye egyetlen pont: a MessageDispatcher.
 */
interface SendingDriver
{
    /** Idempotens az üzenet uuid-jára: kétszeri hívás egy küldést eredményez. */
    public function send(Message $message): DispatchResult;

    public function capabilities(): DriverCapabilities;

    public function health(): DriverHealth;
}
