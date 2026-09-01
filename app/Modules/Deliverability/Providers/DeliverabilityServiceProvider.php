<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Deliverability modul — Blocklist-monitor, DMARC RUA, seed/inbox placement, spam-score, MTA-STS/TLS-RPT/BIMI.
 */
final class DeliverabilityServiceProvider extends ModuleServiceProvider {}
