<?php

declare(strict_types=1);

namespace App\Modules\Journey\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Journey modul — Customer Journey motor: trigger → condition → action → wait → branch, idempotens lépésekkel.
 */
final class JourneyServiceProvider extends ModuleServiceProvider {}
