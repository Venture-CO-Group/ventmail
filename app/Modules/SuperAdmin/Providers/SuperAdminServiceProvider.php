<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Providers;

use App\Models\User;
use App\Modules\Deliverability\Events\BlocklistStateChanged;
use App\Modules\SuperAdmin\Listeners\RaiseBlocklistAlert;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\SuperAdmin\Services\ImpersonationReadOnlyGuard;
use App\Modules\Support\ModuleServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * SuperAdmin modul — operátori konzol (81–88. kritérium).
 *
 * Plan-override · globális suppression · cluster health · IP/warmup felügyelet ·
 * deliverability-riasztás ügyeleti csatornán · audit-kereső · AUDITÁLT impersonation.
 *
 * ## A `platform.admin` képesség ITT dől el, egy helyen
 *
 * A hat tenant-szerep képességeit az `AbilityMap` adja, WORKSPACE-en belül. A
 * platform-admin ezzel szemben tenanteken átnyúlva dolgozik, tehát nem workspace-szerep —
 * és épp ezért nem is szabad a tenant-mátrixba tenni: ott a jelentése elcsúszna („a
 * `owner` a saját workspace-én mindenható" ≠ „mindenhol mindenható").
 *
 * A jogosultság forrása a ventSSO claim, amit a `PlatformAdminSync` minden bejelentkezésnél
 * újraszámol. Az oszlop CACHE: egy SSO-oldali visszavonás nálunk is érvényesül.
 */
final class SuperAdminServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
         | SINGLETON — a memó miatt, nem stílusból.
         |
         | Az írás-tilalom hallgatója MINDEN Eloquent-mentésre lefut, és `app()`-ből kéri a
         | guardot. Új példány = új `Impersonation` = üres memó, tehát mentésenként egy plusz
         | SELECT az `impersonation_sessions`-re (mérve: 20 mentés → +20 lekérdezés, aktív
         | munkamenet nélkül is). Singletonként a feloldás kérésenként EGYSZER történik.
         */
        $this->app->singleton(Impersonation::class);
        $this->app->singleton(ImpersonationReadOnlyGuard::class);
    }

    protected function bootModule(): void
    {
        Gate::define('platform.admin', static fn (User $user): bool => $user->isPlatformAdmin());

        /*
         | A BLOCKLIST-RIASZTÁS az ügyeleti csatornára (88. kritérium).
         |
         | A `PHASE.md` ezt a csatlakozási pontot nevesítette: az esemény és a `message()`
         | metódusa az A6-ban elkészült, a HALLGATÓ viszont nem — a riasztás tehát
         | keletkezett, és senki nem kapta meg.
         */
        Event::listen(BlocklistStateChanged::class, RaiseBlocklistAlert::class);

        /*
         | AZ ÍRÁS-TILALOM aktív impersonation alatt (82. kritérium).
         |
         | A `Impersonation::isReadOnly()` metódusnak NULLA produkciós hívója volt: a
         | security-review megmérte, hogy a platform-admin aktív nézet alatt domaint
         | hozott létre és suppressiont törölt a tenant nevében. A tilalom deklaráció volt,
         | nem védelem.
         |
         | A hallgató MODELL-szinten van, nem middleware-ben: a tilalom nem a HTTP-kérésről
         | szól, hanem az ADATRÓL — egy middleware csak a route-okat védi, a jobok és az
         | artisan-parancsok kimaradnának.
         */
        Event::listen(['eloquent.saving: *', 'eloquent.deleting: *'], function (string $event, array $models): void {
            foreach ($models as $model) {
                if ($model instanceof Model) {
                    app(ImpersonationReadOnlyGuard::class)->assertWritable($model);
                }
            }
        });
    }
}
