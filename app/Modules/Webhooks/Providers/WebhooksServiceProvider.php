<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Webhooks modul — Kimenő webhookok: HMAC-SHA256 + timestamp, retry, dead-letter, replay.
 */
final class WebhooksServiceProvider extends ModuleServiceProvider {}
