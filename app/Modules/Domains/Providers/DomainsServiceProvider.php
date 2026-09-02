<?php

declare(strict_types=1);

namespace App\Modules\Domains\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Domains modul — Küldő domainek hitelesítése: SPF/DKIM/DMARC/PTR/MTA-STS/BIMI, tracking domain, DNS-check.
 */
final class DomainsServiceProvider extends ModuleServiceProvider {}
