<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Events modul — Esemény-modell és Timescale hypertable (message_events). Az A sáv a gazda, a B csak olvas.
 */
final class EventsServiceProvider extends ModuleServiceProvider {}
