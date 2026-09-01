<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Smtp modul — SMTP submission relay (587 STARTTLS / 465 TLS), per-domain hitelesítők. Nyílt relay TILOS.
 */
final class SmtpServiceProvider extends ModuleServiceProvider {}
