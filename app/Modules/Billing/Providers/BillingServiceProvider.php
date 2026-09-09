<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Console\AdvanceDunningCommand;
use App\Modules\Billing\Console\CloseBillingPeriodCommand;
use App\Modules\Billing\Console\SnapshotUsageCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Billing modul — ventPay metering (6 dimenzió), entitlement és dunning.
 *
 * KÁRTYAADATOT SOHA nem lát: a `VentPayClient` kontraktusában sincs ilyen. A ventMail a
 * FOGYÁST méri, a ventPay számláz — és a `dunning_state` is csak TÜKÖR: a ventPay dönti
 * el, hogy valaki fizetett-e, a ventMail annyit tud, hogy emiatt küldhet-e.
 */
final class BillingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CloseBillingPeriodCommand::class,
                AdvanceDunningCommand::class,
                SnapshotUsageCommand::class,
            ]);
        }
    }
}
