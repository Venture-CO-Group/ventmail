<?php

declare(strict_types=1);

namespace App\Modules\Sending\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Sending modul — A küldés szíve: SendingDriver interfész, PreSendGate (6 kapu), sorok, retry, bounce-osztályozás.
 */
final class SendingServiceProvider extends ModuleServiceProvider {}
