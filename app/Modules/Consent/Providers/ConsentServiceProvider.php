<?php

declare(strict_types=1);

namespace App\Modules\Consent\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Consent modul — Double opt-in, jogalap, forrás, ip_hash, verzió. Marketing csak érvényes consenttel.
 */
final class ConsentServiceProvider extends ModuleServiceProvider {}
